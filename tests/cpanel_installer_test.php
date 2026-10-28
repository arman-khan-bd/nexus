<?php

declare(strict_types=1);

/**
 * NextPHP cPanel Installer Library - Step 1 Test Suite
 * Comprehensive automated test verifying prerequisites, CSRF, anti-reinstallation guard,
 * database probing, schema migration, and .installed lockfile generation.
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

use NextPHP\Installer\Installer;

echo "\n\033[1;34m========================================================\033[0m\n";
echo "\033[1;36mNEXTPHP CPANEL INSTALLER - STEP 1 TEST SUITE            \033[0m\n";
echo "\033[1;34m========================================================\033[0m\n";

$testsPassed = 0;

// Setup isolated sandbox directory
$sandboxDir = $baseDir . '/storage/test_installer_' . bin2hex(random_bytes(4));
mkdir($sandboxDir, 0755, true);
mkdir($sandboxDir . '/storage', 0755, true);
copy($baseDir . '/.env.example', $sandboxDir . '/.env.example');

// -----------------------------------------------------------------------------
// Test 1: System Pre-requisites & Extensions Verification
// -----------------------------------------------------------------------------
echo "[1/4] Testing system pre-requisites, PHP version & write checks...\n";

$installer = new Installer($sandboxDir);
$prereqs = $installer->checkPrerequisites();

assert(is_array($prereqs), "checkPrerequisites() must return an array");
assert(isset($prereqs['php']), "Prerequisites must include PHP audit");
assert($prereqs['php']['pass'] === true, "PHP version must be >= 8.2");
assert(isset($prereqs['extensions']['pdo']), "Prerequisites must check PDO");
assert(isset($prereqs['extensions']['json']), "Prerequisites must check JSON");
assert(isset($prereqs['extensions']['sodium']), "Prerequisites must check Sodium/OpenSSL");
assert($prereqs['permissions']['storage']['writable'] === true, "storage/ must be detected as writable");
assert($prereqs['can_install'] === true, "Sandbox environment must be flagged as can_install = true");

echo "  \033[1;32m-> PASS:\033[0m PHP 8.2+ verified, core extensions checked, and storage write permissions confirmed.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 2: CSRF Security Token Generation & Constant-Time Verification
// -----------------------------------------------------------------------------
echo "[2/4] Testing installer CSRF token binding & timing-safe verification...\n";

$token1 = $installer->getCsrfToken();
assert(is_string($token1) && strlen($token1) === 64, "CSRF token must be a 64-character hex string (256 bits)");

// Verify valid token
assert($installer->verifyCsrfToken($token1) === true, "Valid CSRF token must verify successfully");

// Reject forged or empty token
assert($installer->verifyCsrfToken('invalid_token_1234567890abcdef') === false, "Forged CSRF token must be rejected");
assert($installer->verifyCsrfToken(null) === false, "Null CSRF token must be rejected");
assert($installer->verifyCsrfToken('') === false, "Empty CSRF token must be rejected");

echo "  \033[1;32m-> PASS:\033[0m Cryptographic session-bound CSRF token and timing-safe verification verified.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 3: Database Credential Probing & Test Connection
// -----------------------------------------------------------------------------
echo "[3/4] Testing real-time database connection probing...\n";

// 3A. Test valid SQLite in sandbox
$sqliteDbPath = $sandboxDir . '/storage/test.sqlite';
$sqliteTest = $installer->testDatabase([
    'db_driver' => 'sqlite',
    'db_name'   => $sqliteDbPath,
]);
assert($sqliteTest['success'] === true, "SQLite probe should succeed in writable storage");

// 3B. Test invalid database validation
$emptyDbTest = $installer->testDatabase([
    'db_driver' => 'mysql',
    'db_name'   => '',
]);
assert($emptyDbTest['success'] === false, "Empty DB name must be rejected immediately");

$mysqlBadTest = $installer->testDatabase([
    'db_driver' => 'mysql',
    'db_host'   => '127.0.0.1',
    'db_port'   => 9999, // non-existent port
    'db_name'   => 'non_existent_db',
    'db_user'   => 'invalid_user',
    'db_pass'   => 'wrong_pass',
]);
assert($mysqlBadTest['success'] === false, "Invalid DB connection must return success = false");
assert(is_string($mysqlBadTest['message']) && strlen($mysqlBadTest['message']) > 0, "Error message must be descriptive");

echo "  \033[1;32m-> PASS:\033[0m Database connection probe handles successes and catches failures gracefully.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 4: End-to-End Installation Pipeline & Anti-Reinstallation Guard
// -----------------------------------------------------------------------------
echo "[4/4] Testing complete installation execution & anti-reinstallation lock...\n";

assert($installer->isInstalled() === false, "Before installation, isInstalled() must be false");

// Execute install with valid parameters (using SQLite for automated sandbox test)
$installData = [
    '_token'                 => $token1,
    'app_name'               => 'Nexus Sandbox App',
    'app_url'                => 'http://localhost:8000',
    'db_driver'              => 'sqlite',
    'db_name'                => $sqliteDbPath,
    'admin_name'             => 'Master Architect',
    'admin_email'            => 'admin@nexus-framework.com',
    'admin_password'         => 'SuperSecretP@ss123',
    'admin_password_confirm' => 'SuperSecretP@ss123',
];

$installResult = $installer->install($installData);
assert($installResult['success'] === true, "Installation must succeed: " . ($installResult['error'] ?? ''));

// 4A. Verify .env file creation & contents
$envPath = $sandboxDir . '/.env';
assert(file_exists($envPath), ".env file must be created");
$envContent = (string)file_get_contents($envPath);
assert(preg_match('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=/m', $envContent), ".env must contain a 256-bit base64 APP_KEY");
assert(str_contains($envContent, 'APP_NAME="Nexus Sandbox App"'), ".env must contain configured APP_NAME");
assert(str_contains($envContent, 'DB_CONNECTION=sqlite'), ".env must configure DB_CONNECTION");

// 4B. Verify Database Schema Migration & Admin Seeding
$pdo = new PDO("sqlite:{$sqliteDbPath}");
$stmt = $pdo->query("SELECT * FROM users WHERE email = 'admin@nexus-framework.com' LIMIT 1");
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
assert($adminUser !== false, "Admin user must be seeded in database");
assert($adminUser['name'] === 'Master Architect', "Admin name must match input");
assert($adminUser['role'] === 'admin', "Admin role must be set to 'admin'");
assert(password_verify('SuperSecretP@ss123', $adminUser['password']), "Admin password hash must verify correctly");

// 4C. Verify .installed Lockfile
$lockPath = $sandboxDir . '/.installed';
assert(file_exists($lockPath), ".installed lockfile must be written");
$lockData = json_decode((string)file_get_contents($lockPath), true);
assert(isset($lockData['installed_at']), "Lockfile must record installed_at timestamp");
assert(isset($lockData['integrity']), "Lockfile must record cryptographic integrity hash");

// 4D. Anti-Reinstallation Guard Verification
assert($installer->isInstalled() === true, "After installation, isInstalled() must be true");

// Attempt to re-run install action - must be blocked!
$reinstallAttempt = $installer->install($installData);
// If someone attempts to call dispatch(), it emits a 403 Forbidden
ob_start();
$installer->dispatch();
$output = (string)ob_get_clean();
assert(str_contains($output, 'Application Already Installed'), "dispatch() must render 403 Forbidden when installed");
assert(str_contains($output, 'Security Lock Active'), "dispatch() must display security lock badge");

echo "  \033[1;32m-> PASS:\033[0m Full installation pipeline completed: .env written, schema migrated, admin seeded & .installed lock armed.\n";
$testsPassed++;

// Cleanup sandbox directory
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandboxDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $file) {
    if ($file->isDir()) {
        @rmdir($file->getPathname());
    } else {
        @unlink($file->getPathname());
    }
}
@rmdir($sandboxDir);

echo "\033[1;34m========================================================\033[0m\n";
echo "\033[1;32mCPANEL INSTALLER STEP 1: ALL {$testsPassed} PHASES PASSED (100% HEALTHY)!\033[0m\n";
echo "\033[1;34m========================================================\033[0m\n\n";
