<?php

declare(strict_types=1);

/**
 * NextPHP Shield v2 - Step 9 Automated Test Suite
 * InstallerBridge Integration, Uninstalled Interception & Post-Installation Handshake
 */

$baseDir = dirname(__DIR__);

// Load Autoloader
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

use Core\Router\InstallerBridge;
use NextPHP\Installer\Installer;

echo "\n\033[1;34m========================================================\033[0m\n";
echo "\033[1;36mINSTALLER BRIDGE & ENTRY POINT - STEP 9 SUITE           \033[0m\n";
echo "\033[1;34m========================================================\033[0m\n";

$testsPassed = 0;

// Setup test sandbox environment
$sandboxDir = $baseDir . '/storage/test_step9_' . bin2hex(random_bytes(4));
mkdir($sandboxDir, 0755, true);
mkdir($sandboxDir . '/storage', 0755, true);
mkdir($sandboxDir . '/database/migrations', 0755, true);
copy($baseDir . '/.env.example', $sandboxDir . '/.env.example');
copy($baseDir . '/database/migrations/2026_01_01_000001_create_users_table.sql', $sandboxDir . '/database/migrations/2026_01_01_000001_create_users_table.sql');

// -----------------------------------------------------------------------------
// Test 1: InstallerBridge Status Detection (Uninstalled vs Installed)
// -----------------------------------------------------------------------------
echo "[1/4] Testing InstallerBridge::isInstalled() detection logic...\n";

// 1A. Clean sandbox: uninstalled state
assert(InstallerBridge::isInstalled($sandboxDir) === false, "Fresh sandbox without lockfile must be detected as uninstalled");

// 1B. Create primary lockfile: storage/app.installed
file_put_contents($sandboxDir . '/storage/app.installed', json_encode(['installed_at' => date('c')]));
assert(InstallerBridge::isInstalled($sandboxDir) === true, "InstallerBridge must detect primary storage/app.installed lockfile");

// 1C. Clean primary, create secondary root lockfile: .installed
@unlink($sandboxDir . '/storage/app.installed');
assert(InstallerBridge::isInstalled($sandboxDir) === false, "Should be uninstalled when lockfile is removed");

file_put_contents($sandboxDir . '/.installed', json_encode(['installed_at' => date('c')]));
assert(InstallerBridge::isInstalled($sandboxDir) === true, "InstallerBridge must detect secondary root .installed lockfile");

@unlink($sandboxDir . '/.installed');

echo "  \033[1;32m-> PASS:\033[0m Primary (storage/app.installed) and secondary (.installed) detection verified.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 2: Front-Controller index.php Architecture Verification
// -----------------------------------------------------------------------------
echo "[2/4] Testing index.php front-controller integration sequence...\n";

$indexPhpContent = (string)file_get_contents($baseDir . '/index.php');

// Must call InstallerBridge before Env::load
$posBridge = strpos($indexPhpContent, 'InstallerBridge::handle');
$posEnv = strpos($indexPhpContent, 'Env::load');

assert($posBridge !== false, "index.php must invoke InstallerBridge::handle");
assert($posEnv !== false, "index.php must invoke Env::load");
assert($posBridge < $posEnv, "InstallerBridge must execute BEFORE Env::load to protect unconfigured apps");

echo "  \033[1;32m-> PASS:\033[0m index.php invokes InstallerBridge::handle() prior to Env::load() and Kernel boot.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 3: Uninstalled Application Interception & Wizard Dispatch
// -----------------------------------------------------------------------------
echo "[3/4] Testing uninstalled interception and wizard rendering...\n";

$installer = new Installer($sandboxDir);

// Verify installer wizard renders when uninstalled
ob_start();
$installer->renderWizard();
$wizardHtml = (string)ob_get_clean();

assert(str_contains($wizardHtml, 'cPanel Installer'), "Wizard HTML must render cPanel Installer title");
assert(str_contains($wizardHtml, 'System Health'), "Wizard HTML must contain Step 1 System Health Check");
assert(str_contains($wizardHtml, 'Database Connection'), "Wizard HTML must contain Step 2 Database Connection");
assert(str_contains($wizardHtml, 'Administrator Account'), "Wizard HTML must contain Step 3 Administrator Account");

echo "  \033[1;32m-> PASS:\033[0m Uninstalled state renders Shadcn UI Setup Wizard directly to the browser.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 4: Post-Installation Handshake & Auto-Routing Transition
// -----------------------------------------------------------------------------
echo "[4/4] Testing post-installation handshake, lockfile deployment & routing transition...\n";

$dbFile = $sandboxDir . '/storage/app.sqlite';
$csrfToken = $installer->getCsrfToken();

$installPayload = [
    '_token'                 => $csrfToken,
    'app_name'               => 'NextPHP Handshake App',
    'app_url'                => 'http://localhost:8000',
    'db_driver'              => 'sqlite',
    'db_name'                => $dbFile,
    'admin_name'             => 'Handshake Admin',
    'admin_email'            => 'admin@handshake.test',
    'admin_password'         => 'SuperSecretP@ssw0rd!',
    'admin_password_confirm' => 'SuperSecretP@ssw0rd!',
];

$installResult = $installer->install($installPayload);
assert($installResult['success'] === true, "Installation must succeed");
assert($installResult['redirect'] === 'http://localhost:8000', "Install result must return dashboard redirect URL");

// Handshake verification: storage/app.installed lockfile must exist
$lockPath = $sandboxDir . '/storage/app.installed';
assert(file_exists($lockPath), "storage/app.installed must be created by the installer");

// InstallerBridge must now confirm installed state
assert(InstallerBridge::isInstalled($sandboxDir) === true, "InstallerBridge must confirm app is installed");
assert(InstallerBridge::handle($sandboxDir) === true, "InstallerBridge::handle() must return true when installed");

// Clean up sandbox
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

echo "  \033[1;32m-> PASS:\033[0m Post-installation handshake complete: lockfile deployed and traffic allowed to normal routes.\n";
$testsPassed++;

echo "\033[1;34m========================================================\033[0m\n";
echo "\033[1;32mSTEP 9 COMPLETED: ALL {$testsPassed} PHASES PASSED (100% HEALTHY)!\033[0m\n";
echo "\033[1;34m========================================================\033[0m\n\n";
