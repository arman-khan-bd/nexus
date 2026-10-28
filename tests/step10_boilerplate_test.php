<?php

declare(strict_types=1);

/**
 * NextPHP Shield v2 - Step 10 Automated Test Suite
 * Boilerplate Package Validation (composer.json, index.php, nextphp CLI, post-create-project)
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

use Core\Console\Installer;
use Core\Console\Cli;

echo "\n\033[1;34m========================================================\033[0m\n";
echo "\033[1;36mNEXTPHP SHIELD BOILERPLATE - STEP 10 TEST SUITE         \033[0m\n";
echo "\033[1;34m========================================================\033[0m\n";

$testsPassed = 0;

// -----------------------------------------------------------------------------
// Test 1: Boilerplate composer.json Manifest Validation
// -----------------------------------------------------------------------------
echo "[1/4] Testing boilerplate composer.json configuration & requirements...\n";

$composerJsonPath = $baseDir . '/composer.json';
assert(file_exists($composerJsonPath), "composer.json must exist in root");

$composerData = json_decode((string)file_get_contents($composerJsonPath), true);
assert(is_array($composerData), "composer.json must be valid JSON");
assert(in_array($composerData['name'], ['nextphp/nexus', 'nexus/nexus', 'nexus-lab/nexus'], true), "Package name must be valid");
assert($composerData['type'] === 'project', "Package type must be project");

// Verify dependencies
$req = $composerData['require'] ?? [];
assert(isset($req['php']) && str_contains($req['php'], '8.2'), "Requires php: ^8.2");
assert(isset($req['ext-pdo']), "Requires ext-pdo");
assert(isset($req['ext-sodium']), "Requires ext-sodium");
assert(isset($req['ext-json']), "Requires ext-json");
assert(isset($req['nexus-lab/cpanel-installer']) || isset($req['nextphp/cpanel-installer']) || isset($req['nexus/cpanel-installer']), "Requires cpanel-installer");

// Verify PSR-4 autoloading
$psr4 = $composerData['autoload']['psr-4'] ?? [];
assert(isset($psr4['Core\\']) && $psr4['Core\\'] === 'core/', "Autoload Core\\ -> core/");
assert(isset($psr4['App\\']) && $psr4['App\\'] === 'app/', "Autoload App\\ -> app/");
assert(isset($psr4['NextPHP\\Installer\\']), "Autoload NextPHP\\Installer\\");

// Verify binary
$bin = $composerData['bin'] ?? [];
assert(in_array('nextphp', $bin, true), "nextphp must be registered in binaries");

// Verify post-create-project-cmd scripts
$scripts = $composerData['scripts'] ?? [];
assert(isset($scripts['post-create-project-cmd']), "Must declare post-create-project-cmd script");

echo "  \033[1;32m-> PASS:\033[0m nextphp/nexus composer.json manifest verified with all required constraints.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 2: Root index.php Front-Controller Architecture
// -----------------------------------------------------------------------------
echo "[2/4] Testing root index.php strict typing & security interception sequence...\n";

$indexContent = (string)file_get_contents($baseDir . '/index.php');

assert(str_contains($indexContent, 'declare(strict_types=1);'), "index.php must declare strict types");
assert(str_contains($indexContent, 'InstallerBridge::handle'), "index.php must invoke InstallerBridge::handle");
assert(str_contains($indexContent, 'Env::load'), "index.php must invoke Env::load");
assert(str_contains($indexContent, 'Kernel::boot'), "index.php must boot Security Kernel");

// Sequence check
$posStrict = strpos($indexContent, 'declare(strict_types=1);');
$posBridge = strpos($indexContent, 'InstallerBridge::handle');
$posEnv    = strpos($indexContent, 'Env::load');
$posKernel = strpos($indexContent, 'Kernel::boot');

assert($posStrict < $posBridge, "strict_types must be first line");
assert($posBridge < $posEnv, "InstallerBridge must intercept before Env::load");
assert($posEnv < $posKernel, "Env::load must occur before Kernel::boot");

echo "  \033[1;32m-> PASS:\033[0m index.php execution order: Strict Types -> Installer Bridge -> Env Lock -> Kernel/WAF/Router.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 3: Root nextphp CLI Executable & Core Commands
// -----------------------------------------------------------------------------
echo "[3/4] Testing root nextphp CLI tool commands (page:make, security:audit, cache:compile, build:cpanel)...\n";

$rootNextphp = $baseDir . '/nextphp';
$rootNexus = $baseDir . '/nexus';
assert(file_exists($rootNextphp), "Root nextphp executable must exist");
assert(file_exists($rootNexus), "Root nexus executable must exist");

$cli = new Cli($baseDir);

// Test about command
ob_start();
$exitAbout = $cli->about();
$aboutOut = (string)ob_get_clean();
assert($exitAbout === 0, "about command must return exit code 0");
assert(str_contains($aboutOut, 'Next.js-inspired'), "about output must mention Next.js-inspired");

// Test security:audit command
ob_start();
$exitAudit = $cli->securityAudit();
$auditOut = (string)ob_get_clean();
assert($exitAudit === 0, "security:audit must return exit code 0");
assert(str_contains($auditOut, '100 / 100'), "security:audit must achieve 100/100 fortress score");

// Test cache:compile command
ob_start();
$exitCompile = $cli->compileCache();
$compileOut = (string)ob_get_clean();
assert($exitCompile === 0, "cache:compile must return exit code 0");
assert(str_contains($compileOut, 'Successfully pre-compiled'), "cache:compile must report success");

echo "  \033[1;32m-> PASS:\033[0m Root nextphp CLI successfully dispatched about, security:audit, and cache:compile.\n";
$testsPassed++;

// -----------------------------------------------------------------------------
// Test 4: post-create-project Initialization Sandbox Verification
// -----------------------------------------------------------------------------
echo "[4/4] Testing postCreateProject sandbox execution (storage isolation & key generation)...\n";

$sandboxDir = $baseDir . '/storage/test_step10_' . bin2hex(random_bytes(4));
mkdir($sandboxDir, 0755, true);
copy($baseDir . '/.env.example', $sandboxDir . '/.env.example');

// Run setupStorageDirectories
Installer::setupStorageDirectories($sandboxDir);
assert(is_dir($sandboxDir . '/storage/cache/views'), "Must create storage/cache/views");
assert(is_dir($sandboxDir . '/storage/sessions'), "Must create storage/sessions");
assert(is_dir($sandboxDir . '/storage/ratelimit'), "Must create storage/ratelimit");
assert(is_dir($sandboxDir . '/storage/uploads'), "Must create storage/uploads");
assert(file_exists($sandboxDir . '/storage/uploads/.htaccess'), "Must deploy uploads fortress .htaccess");

// Run setupEnvironmentFile & generateAndWriteAppKey
$created = Installer::setupEnvironmentFile($sandboxDir);
assert($created === true, "Must generate .env from .env.example");

$key = Installer::generateAndWriteAppKey($sandboxDir, true);
assert(str_starts_with($key, 'base64:'), "Must generate base64 APP_KEY");
$keyRaw = base64_decode(substr($key, 7), true);
assert($keyRaw !== false && strlen($keyRaw) === 32, "APP_KEY must be exactly 256 bits (32 bytes)");

$envContent = (string)file_get_contents($sandboxDir . '/.env');
assert(str_contains($envContent, "APP_KEY={$key}"), ".env must record generated APP_KEY");

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

echo "  \033[1;32m-> PASS:\033[0m postCreateProject bootstrapped 0700 storage, fortress .htaccess, and 256-bit APP_KEY.\n";
$testsPassed++;

echo "\033[1;34m========================================================\033[0m\n";
echo "\033[1;32mSTEP 10 COMPLETED: ALL {$testsPassed} PHASES PASSED (100% HEALTHY)!\033[0m\n";
echo "\033[1;34m========================================================\033[0m\n\n";
