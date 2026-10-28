<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);

// Register PSR-4 autoloader
spl_autoload_register(function (string $class) use ($baseDir) {
    $prefix = 'Core\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $file = $baseDir . '/core/' . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Core\Env;
use Core\Security\Crypto;
use Core\Database\DB;
use Core\Database\QueryBuilder;
use Core\Database\Model;

echo "========================================================\n";
echo "NEXUS DATABASE & ORM LAYER - STEP 4 TEST SUITE\n";
echo "========================================================\n";

Env::load($baseDir . '/.env');

// Set in-memory SQLite connection for isolated testing
$sqlitePdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
DB::setPdo($sqlitePdo);

// -----------------------------------------------------------------------------
// 1. PDO HARDENED ATTRIBUTES TEST
// -----------------------------------------------------------------------------
echo "[1/3] Testing PDO Singleton connection & hardened attributes...\n";

$pdo = DB::connection();
assert($pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'ERRMODE must be ERRMODE_EXCEPTION');
assert($pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC, 'DEFAULT_FETCH_MODE must be FETCH_ASSOC');
assert($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false, 'EMULATE_PREPARES must be false (native prepared statements)');
echo "  -> PASS: Hardened PDO attributes active (EMULATE_PREPARES=false, ERRMODE_EXCEPTION)\n";

// Initialize test table schema
DB::statement("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        api_key TEXT,
        phone TEXT,
        role TEXT DEFAULT 'user',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

// -----------------------------------------------------------------------------
// 2. FLUENT QUERY BUILDER & IDENTIFIER INJECTION DEFENSE
// -----------------------------------------------------------------------------
echo "[2/3] Testing Fluent Query Builder & SQL Injection prevention...\n";

// 2A. Identifier Injection Mitigation
$caughtTableInjection = false;
try {
    DB::table("users; DROP TABLE users; --")->get();
} catch (InvalidArgumentException $e) {
    $caughtTableInjection = true;
    assert(str_contains($e->getMessage(), 'NEXUS SQL DEFENSE'), 'Expected defense exception for table');
}
assert($caughtTableInjection, "Table name injection was not intercepted!");

$caughtColumnInjection = false;
try {
    DB::table('users')->where("id; DROP TABLE users; --", '=', 1)->get();
} catch (InvalidArgumentException $e) {
    $caughtColumnInjection = true;
    assert(str_contains($e->getMessage(), 'NEXUS SQL DEFENSE'), 'Expected defense exception for column');
}
assert($caughtColumnInjection, "Column name injection was not intercepted!");

$caughtOperatorInjection = false;
try {
    DB::table('users')->where('id', 'UNION SELECT 1', 1)->get();
} catch (InvalidArgumentException $e) {
    $caughtOperatorInjection = true;
    assert(str_contains($e->getMessage(), 'Disallowed comparison operator'), 'Expected defense exception for operator');
}
assert($caughtOperatorInjection, "Operator injection was not intercepted!");
echo "  -> PASS: Identifier & operator injection attempts intercepted and blocked\n";

// 2B. Insert and Query Execution
$id1 = DB::table('users')->insertGetId([
    'name'    => 'John Doe',
    'email'   => 'john@example.com',
    'api_key' => 'plain_key_123',
    'phone'   => '+1-555-0100',
    'role'    => 'admin',
]);
assert($id1 === 1, 'First insert ID must equal 1');

$id2 = DB::table('users')->insertGetId([
    'name'    => 'Jane Smith',
    'email'   => 'jane@example.com',
    'api_key' => 'plain_key_456',
    'phone'   => '+1-555-0200',
    'role'    => 'user',
]);
assert($id2 === 2, 'Second insert ID must equal 2');

// Where, OrWhere, First, Count, Exists
$user = DB::table('users')->where('email', '=', 'john@example.com')->first();
assert($user !== null && $user['name'] === 'John Doe', 'Query by email failed');

assert(DB::table('users')->count() === 2, 'Total users count must be 2');
assert(DB::table('users')->where('role', '=', 'admin')->exists(), 'Admin user must exist');

// WhereIn
$inUsers = DB::table('users')->whereIn('id', [1, 2, 999])->get();
assert(count($inUsers) === 2, 'WhereIn count must be 2');

// Update and Delete
$affected = DB::table('users')->where('id', '=', 2)->update(['role' => 'editor']);
assert($affected === 1, 'Updated rows must be 1');
$updatedUser = DB::table('users')->find(2);
assert($updatedUser['role'] === 'editor', 'Role was not updated to editor');

// Transactions
DB::transaction(function () {
    DB::table('users')->insert([
        'name'  => 'Tx User',
        'email' => 'tx@example.com',
    ]);
});
assert(DB::table('users')->where('email', '=', 'tx@example.com')->exists(), 'Transaction must commit');

$caughtTxRollback = false;
try {
    DB::transaction(function () {
        DB::table('users')->insert([
            'name'  => 'Rollback User',
            'email' => 'rollback@example.com',
        ]);
        throw new RuntimeException("Simulated Failure");
    });
} catch (\Throwable) {
    $caughtTxRollback = true;
}
assert($caughtTxRollback, 'Transaction must throw on failure');
assert(!DB::table('users')->where('email', '=', 'rollback@example.com')->exists(), 'Failed transaction must roll back');
echo "  -> PASS: Query builder CRUD, aggregates, WhereIn, and atomic transactions verified\n";

// -----------------------------------------------------------------------------
// 3. AUTOMATIC MODEL ENCRYPTION AT REST ($encrypt)
// -----------------------------------------------------------------------------
echo "[3/3] Testing Model with transparent Libsodium/AES field encryption...\n";

// Define concrete test model
class TestUser extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'api_key', 'phone', 'role'];
    protected array $encrypt = ['api_key', 'phone']; // Sensitive columns
    protected array $hidden = ['phone']; // Hidden from serialization
}

$secretApiKey = 'nexus_live_sec_994827104859';
$secretPhone = '+1-800-555-SECRET';

$newUser = TestUser::create([
    'name'    => 'Cryptic User',
    'email'   => 'crypto@nexus.lan',
    'api_key' => $secretApiKey,
    'phone'   => $secretPhone,
    'role'    => 'developer',
]);

assert($newUser->exists() === true, 'Model must exist after creation');
assert($newUser->id > 0, 'Model must have assigned primary key ID');

// Verify that on disk in the database, the fields are ENCRYPTED (NOT plaintext)
$rawDbRow = DB::select("SELECT * FROM users WHERE email = ?", ['crypto@nexus.lan'])[0];

// The plaintext secret must NOT appear anywhere in the database!
assert(!str_contains($rawDbRow['api_key'], $secretApiKey), 'RAW DB api_key must NOT contain plaintext!');
assert(!str_contains($rawDbRow['phone'], $secretPhone), 'RAW DB phone must NOT contain plaintext!');

// Must contain authenticated encryption envelope (v1:sodium: or v1:gcm:)
assert(str_starts_with($rawDbRow['api_key'], 'v1:sodium:') || str_starts_with($rawDbRow['api_key'], 'v1:gcm:'), 'Raw api_key must be encrypted');
assert(str_starts_with($rawDbRow['phone'], 'v1:sodium:') || str_starts_with($rawDbRow['phone'], 'v1:gcm:'), 'Raw phone must be encrypted');
echo "  -> PASS: Database columns (api_key, phone) confirmed encrypted at rest in database\n";

// Retrieve model using ORM and verify transparent automatic decryption
$fetchedUser = TestUser::find($newUser->id);
assert($fetchedUser !== null, 'Fetched user must not be null');
assert($fetchedUser->api_key === $secretApiKey, 'Model must transparently decrypt api_key');
assert($fetchedUser->phone === $secretPhone, 'Model must transparently decrypt phone');
assert($fetchedUser->name === 'Cryptic User', 'Non-encrypted attribute must match');

// Verify toArray() and Serialization
$userArray = $fetchedUser->toArray();
assert($userArray['api_key'] === $secretApiKey, 'toArray() must include decrypted api_key');
assert(!array_key_exists('phone', $userArray), 'toArray() must omit hidden attribute (phone)');
assert(str_contains($fetchedUser->toJson(), 'nexus_live_sec_994827104859'), 'toJson() must serialize cleanly');
echo "  -> PASS: Transparent automatic decryption on model access & hidden attribute masking\n";

echo "========================================================\n";
echo "STEP 4 ALL DATABASE & MODEL TESTS PASSED! (100% HEALTHY)\n";
echo "========================================================\n";
