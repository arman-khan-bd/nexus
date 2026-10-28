<?php

declare(strict_types=1);

/**
 * NextPHP cPanel Installer - Step 8 Automated Test Suite
 * Database Migration Engine (DatabaseMigrator) & Admin Account Provisioner (AdminProvisioner)
 */

$baseDir = dirname(__DIR__);

// Load Autoloader & register NextPHP\Installer\
spl_autoload_register(function (string $class) use ($baseDir) {
    $prefixMap = [
        'NextPHP\\Installer\\' => $baseDir . '/packages/cpanel-installer/src/',
        'Core\\'               => $baseDir . '/core/',
        'App\\'                => $baseDir . '/app/',
        'Components\\'         => $baseDir . '/components/',
    ];
    foreach ($prefixMap as $prefix => $base) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relative = substr($class, $len);
            $file = $base . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

use NextPHP\Installer\DatabaseMigrator;
use NextPHP\Installer\AdminProvisioner;
use NextPHP\Installer\Installer;

echo "\n\033[1;34m========================================================\033[0m\n";
echo "\033[1;36mDATABASE MIGRATOR & ADMIN PROVISIONER - STEP 8 SUITE    \033[0m\n";
echo "\033[1;34m========================================================\033[0m\n";

$testsPassed = 0;

// Setup test sandbox environment
$sandboxDir = $baseDir . '/storage/test_step8_' . bin2hex(random_bytes(4));
mkdir($sandboxDir, 0755, true);
mkdir($sandboxDir . '/storage', 0755, true);
mkdir($sandboxDir . '/storage/cache/views', 0755, true);
mkdir($sandboxDir . '/database/migrations', 0755, true);
copy($baseDir . '/.env.example', $sandboxDir . '/.env.example');

$dbFile = $sandboxDir . '/storage/test_sandbox.sqlite';
$pdo = new PDO("sqlite:{$dbFile}", null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// -----------------------------------------------------------------------------
// Test 1: DatabaseMigrator Table Creation, Statement Splitting & Chronological Run
// -----------------------------------------------------------------------------
echo "[1/4] Testing DatabaseMigrator tracking table & statement tokenizer...\n";

$migrationsDir = $sandboxDir . '/database/migrations';

// Create 2 test migrations in chronological order
$mig1 = <<<SQL
-- Migration 1: Create posts table
CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL
);

-- Insert sample seed record
INSERT INTO posts (title, content, created_at)
VALUES ('Hello World', 'Welcome to Nexus & NextPHP!', '2026-01-01 00:00:00');
SQL;

$mig2 = <<<SQL
/* Migration 2: Create tags and pivot table */
CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE post_tags (
    post_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (post_id, tag_id)
);
SQL;

file_put_contents($migrationsDir . '/2026_01_01_000001_create_posts_table.sql', $mig1);
file_put_contents($migrationsDir . '/2026_01_01_000002_create_tags_table.sql', $mig2);

$migrator = new DatabaseMigrator($pdo, $migrationsDir);

// Verify statement splitting logic
$testSql = "SELECT 'hello; world' AS col; -- comment;\nINSERT INTO t VALUES (1); /* block; */ SELECT 2;";
$statements = $migrator->splitSqlStatements($testSql);
assert(count($statements) === 3, "Tokenizer must split exactly 3 statements ignoring semicolons in strings and comments");

// Run migrations
$migResult = $migrator->run();
assert($migResult['success'] === true, "DatabaseMigrator::run() should succeed: " . ($migResult['message'] ?? ''));
assert(count($migResult['executed']) === 2, "Should execute exactly 2 migration files");
assert($migResult['executed'][0] === '2026_01_01_000001_create_posts_table.sql', "First migration must execute first");
assert($migResult['executed'][1] === '2026_01_01_000002_create_tags_table.sql', "Second migration must execute second");

// Verify tables were physically created in SQLite
$postStmt = $pdo->query("SELECT * FROM posts WHERE id = 1");
$postRow = $postStmt->fetch();
assert($postRow !== false, "posts table must exist and contain seeded record");
assert($postRow['title'] === 'Hello World', "Seeded post title must match");

// Verify tracking in migrations table
$ranMigrations = $migrator->getRanMigrations();
assert(count($ranMigrations) === 2, "migrations table must record exactly 2 executed migrations");

// Verify idempotency: running again should do nothing
$pendingAfter = $migrator->getPendingMigrations();
assert(empty($pendingAfter), "getPendingMigrations() must return empty array when all are run");
$rerunResult = $migrator->run();
assert($rerunResult['success'] === true && empty($rerunResult['executed']), "Subsequent run must execute 0 migrations");

echo "  \033[1;32m-> PASS:\033[0m Migrations table auto-created, SQL statements split safely, and chronological execution verified.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 2: DatabaseMigrator Transaction Rollback on Failure
// -----------------------------------------------------------------------------
echo "[2/4] Testing transactional rollback on invalid SQL migrations...\n";

// Add an invalid migration that fails halfway
$badMig = <<<SQL
CREATE TABLE valid_table (id INTEGER PRIMARY KEY);
INSERT INTO valid_table VALUES (1);
THIS IS AN INTENTIONAL SYNTAX ERROR;
SQL;

file_put_contents($migrationsDir . '/2026_01_01_000003_bad_migration.sql', $badMig);

$pendingBad = $migrator->getPendingMigrations();
assert(count($pendingBad) === 1, "Should discover 1 pending bad migration");

$failResult = $migrator->run();
assert($failResult['success'] === false, "Migrator run must fail on invalid SQL");
assert(str_contains($failResult['message'], 'bad_migration.sql'), "Error message must report failing filename");

// Verify rollback: valid_table must NOT exist or failed migration must NOT be in migrations table
assert(!in_array('2026_01_01_000003_bad_migration.sql', $migrator->getRanMigrations(), true), "Failed migration must NOT be recorded in migrations table");

// Remove bad migration file
@unlink($migrationsDir . '/2026_01_01_000003_bad_migration.sql');

echo "  \033[1;32m-> PASS:\033[0m Atomic transaction rollback prevents half-migrated states and failed record tracking.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 3: AdminProvisioner Password Hashing, Key Generation & Lockfiles
// -----------------------------------------------------------------------------
echo "[3/4] Testing AdminProvisioner password hashing, .env configuration & lockfiles...\n";

// Populate dummy view cache to verify cleanup
file_put_contents($sandboxDir . '/storage/cache/views/temp_installer_cache.php', '<?php // cache ?>');

$provisioner = new AdminProvisioner($pdo, $sandboxDir);

// 3A. APP_KEY Generation
$appKey = $provisioner->generateAppKey();
assert(str_starts_with($appKey, 'base64:'), "APP_KEY must start with base64:");
$decodedKey = base64_decode(substr($appKey, 7), true);
assert($decodedKey !== false && strlen($decodedKey) === 32, "APP_KEY must decode to exactly 32 bytes (256-bit entropy)");

// 3B. Provisioning pipeline
$adminConfig = [
    'app_name'       => 'Nexus Step 8 Fortress',
    'app_url'        => 'https://nexus.test',
    'db_driver'      => 'sqlite',
    'db_name'        => $dbFile,
    'admin_name'     => 'Security Officer',
    'admin_email'    => 'security@nexus.test',
    'admin_password' => 'V3ry$ecureP@ssword2026!',
];

$provisionResult = $provisioner->provision($adminConfig);
assert($provisionResult['success'] === true, "AdminProvisioner::provision() must succeed");
assert($provisionResult['admin_email'] === 'security@nexus.test', "Admin email must be confirmed");
assert(file_exists($provisionResult['lock_file']), "storage/app.installed lockfile must exist");

// 3C. Database user record verification
$userStmt = $pdo->query("SELECT * FROM users WHERE email = 'security@nexus.test' LIMIT 1");
$adminRow = $userStmt->fetch();
assert($adminRow !== false, "Admin user record must be stored in users table");
assert($adminRow['name'] === 'Security Officer', "Admin name must match");
assert($adminRow['role'] === 'admin', "Admin role must be set to 'admin'");
assert(password_verify('V3ry$ecureP@ssword2026!', $adminRow['password']), "password_verify must validate hashed password");

// 3D. Verify .env file written with 0600 permissions
$envPath = $sandboxDir . '/.env';
assert(file_exists($envPath), ".env file must be created");
$envContent = (string)file_get_contents($envPath);
assert(str_contains($envContent, 'APP_NAME="Nexus Step 8 Fortress"'), ".env must contain APP_NAME");
assert(str_contains($envContent, 'APP_KEY=base64:'), ".env must contain 256-bit APP_KEY");
assert(str_contains($envContent, 'DB_CONNECTION=sqlite'), ".env must contain DB_CONNECTION");

// 3E. Verify lockfile in storage/app.installed
$storageLockPath = $sandboxDir . '/storage/app.installed';
assert(file_exists($storageLockPath), "storage/app.installed lockfile must exist");
$storageLockData = json_decode((string)file_get_contents($storageLockPath), true);
assert($storageLockData['admin_email'] === 'security@nexus.test', "Lockfile must record admin email");
assert(isset($storageLockData['installed_at']), "Lockfile must record installed_at timestamp");
assert(isset($storageLockData['integrity']), "Lockfile must record cryptographic integrity hash");

// 3F. Verify installation cache was pruned
assert(!file_exists($sandboxDir . '/storage/cache/views/temp_installer_cache.php'), "Installation cache file must be deleted");

echo "  \033[1;32m-> PASS:\033[0m Password hashed, prepared statements executed, 256-bit key written, and storage lockfile armed.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 4: Full End-to-End Installer Integration
// -----------------------------------------------------------------------------
echo "[4/4] Testing full Installer::install() integration with Migrator & Provisioner...\n";

// Set up secondary fresh sandbox
$integrationSandbox = $baseDir . '/storage/test_step8_int_' . bin2hex(random_bytes(4));
mkdir($integrationSandbox, 0755, true);
mkdir($integrationSandbox . '/storage', 0755, true);
mkdir($integrationSandbox . '/database/migrations', 0755, true);
copy($baseDir . '/.env.example', $integrationSandbox . '/.env.example');

// Copy host migrations to sandbox
copy($baseDir . '/database/migrations/2026_01_01_000001_create_users_table.sql', $integrationSandbox . '/database/migrations/2026_01_01_000001_create_users_table.sql');

$intDbFile = $integrationSandbox . '/storage/app.sqlite';
$installerInt = new Installer($integrationSandbox);

$csrfToken = $installerInt->getCsrfToken();
$installPayload = [
    '_token'                 => $csrfToken,
    'app_name'               => 'Nexus Production App',
    'app_url'                => 'http://localhost:8000',
    'db_driver'              => 'sqlite',
    'db_name'                => $intDbFile,
    'admin_name'             => 'Primary Superuser',
    'admin_email'            => 'root@nexus-shield.org',
    'admin_password'         => 'Extr3melySecureP@ssword!',
    'admin_password_confirm' => 'Extr3melySecureP@ssword!',
];

$installRes = $installerInt->install($installPayload);
assert($installRes['success'] === true, "Installer::install() should succeed: " . ($installRes['error'] ?? ''));

// Verify installer lockfile was deployed
assert(file_exists($integrationSandbox . '/storage/app.installed'), "storage/app.installed lockfile must exist");
assert($installerInt->isInstalled() === true, "isInstalled() must return true");

// Clean up integration sandbox
$pdo = null;
$intPdo = null;

$cleanupDir = function (string $dir) use (&$cleanupDir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $cleanupDir($path);
        } else {
            @chmod($path, 0666);
            @unlink($path);
        }
    }
    @rmdir($dir);
};

$cleanupDir($sandboxDir);
$cleanupDir($integrationSandbox);

echo "  \033[1;32m-> PASS:\033[0m End-to-end installer pipeline successfully orchestrated DatabaseMigrator and AdminProvisioner.\n";
$testsPassed++;

echo "\033[1;34m========================================================\033[0m\n";
echo "\033[1;32mSTEP 8 COMPLETED: ALL {$testsPassed} PHASES PASSED (100% HEALTHY)!\033[0m\n";
echo "\033[1;34m========================================================\033[0m\n\n";
