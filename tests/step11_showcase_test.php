<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);

// PSR-4 Autoloader
spl_autoload_register(function (string $class) use ($baseDir) {
    $prefixes = [
        'Core\\'       => $baseDir . '/core/',
        'App\\'        => $baseDir . '/app/',
        'Components\\' => $baseDir . '/components/',
    ];

    foreach ($prefixes as $prefix => $dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, $len)) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

use Core\Env;
use Core\Http\Request;
use Core\Router\ServerAction;
use Core\Compiler\JsxCompiler;
use Core\Security\Escaper;

echo "========================================================\n";
echo "NEXUS SHOWCASE & MASTER LAYOUT - STEP 11 TEST SUITE\n";
echo "========================================================\n";

Env::load($baseDir . '/.env');

// -----------------------------------------------------------------------------
// 1. MASTER LAYOUT (pages/layout.php) STATIC VERIFICATION
// -----------------------------------------------------------------------------
echo "[1/4] Verifying pages/layout.php structure, design tokens, and CSP nonce...\n";

$layoutFile = $baseDir . '/pages/layout.php';
assert(file_exists($layoutFile), 'pages/layout.php must exist');

$layoutContent = (string)file_get_contents($layoutFile);

// Check Shadcn HSL CSS tokens
assert(str_contains($layoutContent, '--background:'), 'Layout missing --background HSL token');
assert(str_contains($layoutContent, '--primary:'), 'Layout missing --primary HSL token');
assert(str_contains($layoutContent, '--destructive:'), 'Layout missing --destructive HSL token');
assert(str_contains($layoutContent, '.dark {'), 'Layout missing .dark mode HSL tokens');

// Check Dark/Light Mode Toggle
assert(str_contains($layoutContent, 'darkMode: true'), 'Layout missing darkMode Alpine state');
assert(str_contains($layoutContent, 'toggleTheme()'), 'Layout missing toggleTheme function');
assert(str_contains($layoutContent, 'localStorage.setItem(\'theme\''), 'Layout missing theme persistence');

// Check Dynamic CSP Nonce
assert(str_contains($layoutContent, '$cspNonce = base64_encode(random_bytes(16));'), 'Layout missing random CSP nonce generation');
assert(str_contains($layoutContent, 'Content-Security-Policy'), 'Layout missing Content-Security-Policy');
assert(str_contains($layoutContent, 'nonce="<?= $cspNonce ?>"'), 'Layout missing nonce attribute on inline tags');

// Check Scripts & Libraries
assert(str_contains($layoutContent, 'cdn.tailwindcss.com'), 'Layout missing Tailwind CSS CDN script');
assert(str_contains($layoutContent, 'alpinejs'), 'Layout missing Alpine.js script');
assert(str_contains($layoutContent, '@alpinejs/morph'), 'Layout missing Alpine Morph plugin script');
assert(str_contains($layoutContent, 'lucide'), 'Layout missing Lucide icon library script');
assert(str_contains($layoutContent, '/assets/js/nextphp-spa.js'), 'Layout missing nextphp-spa.js SPA bridge');
assert(str_contains($layoutContent, 'id="nexus-root"'), 'Layout missing #nexus-root SPA viewport container');

echo "  -> PASS: Master layout contains Tailwind HSL tokens, dark mode toggle, CSP nonces, and SPA bridge\n";

// -----------------------------------------------------------------------------
// 2. SHOWCASE HOMEPAGE (pages/page.php) STATIC VERIFICATION
// -----------------------------------------------------------------------------
echo "[2/4] Verifying pages/page.php Live Security Badge Grid & Components...\n";

$pageFile = $baseDir . '/pages/page.php';
assert(file_exists($pageFile), 'pages/page.php must exist');

$pageContent = (string)file_get_contents($pageFile);

// Check 5 Core Defenses in Badge Grid
assert(str_contains($pageContent, 'WAF Application Firewall'), 'Page missing WAF Firewall card');
assert(str_contains($pageContent, 'Active / SQLi & XSS Blocked'), 'Page missing WAF active status badge');

assert(str_contains($pageContent, 'Anti-DDoS Rate Limiter'), 'Page missing Anti-DDoS Rate Limiter card');
assert(str_contains($pageContent, '60 req/min &bull; 5 POST/10s'), 'Page missing Rate Limiter badge');

assert(str_contains($pageContent, 'Cryptographic Session Guard'), 'Page missing Session Guard card');
assert(str_contains($pageContent, 'Libsodium Encrypted'), 'Page missing Libsodium badge');

assert(str_contains($pageContent, 'CSP Nonce Protection'), 'Page missing CSP Nonce card');
assert(str_contains($pageContent, 'Dynamic Nonce Active'), 'Page missing CSP Nonce badge');

assert(str_contains($pageContent, 'Zero-AJAX SPA Engine'), 'Page missing Zero-AJAX SPA card');
assert(str_contains($pageContent, 'Alpine Morph Active'), 'Page missing SPA Alpine Morph badge');

// Check Interactive Security Probe Demo section
assert(str_contains($pageContent, 'id="probe-section"'), 'Page missing #probe-section');
assert(str_contains($pageContent, 'testSecurity'), 'Page missing testSecurity server action reference');
assert(str_contains($pageContent, 'Cross-Site Scripting (XSS)'), 'Page missing XSS preset');
assert(str_contains($pageContent, 'SQL Injection Probe'), 'Page missing SQLi preset');
assert(str_contains($pageContent, 'Directory Traversal & Null Byte'), 'Page missing Path Traversal preset');
assert(str_contains($pageContent, 'Execute Security Probe'), 'Page missing Execute Probe button');

// Check Shadcn Component Registry section
assert(str_contains($pageContent, 'id="components-section"'), 'Page missing #components-section');
assert(str_contains($pageContent, '<Button'), 'Page missing <Button> JSX component');
assert(str_contains($pageContent, '<Card'), 'Page missing <Card> JSX component');
assert(str_contains($pageContent, '<Badge'), 'Page missing <Badge> JSX component');
assert(str_contains($pageContent, '<Input'), 'Page missing <Input> JSX component');
assert(str_contains($pageContent, '<Dialog'), 'Page missing <Dialog> JSX component');
assert(str_contains($pageContent, '<Lucide'), 'Page missing <Lucide> JSX component');

echo "  -> PASS: Showcase homepage contains 5-defense security grid, probe demo, and component showcase\n";

// -----------------------------------------------------------------------------
// 3. SERVER ACTION (testSecurity & ping) EXECUTION TESTS
// -----------------------------------------------------------------------------
echo "[3/4] Testing Server Action execution (testSecurity XSS/SQLi neutralization & ping)...\n";

// Require page.php to register the server actions
ob_start();
require_once $pageFile;
ob_end_clean();

// Test testSecurity Action registration
$testSecurityHandler = ServerAction::get('testSecurity');
assert($testSecurityHandler !== null, 'testSecurity server action must be registered in ServerAction');

// Test with XSS script injection
$xssInput = [
    'test_payload' => '<script>alert("XSS Vector Exploit")</script>',
    'category'     => 'Cross-Site Scripting (XSS)',
];

$xssResult = $testSecurityHandler($xssInput, null);
assert(is_array($xssResult), 'testSecurity must return an array');
assert($xssResult['success'] === true, 'testSecurity must return success true');
assert($xssResult['data']['raw'] === '<script>alert("XSS Vector Exploit")</script>', 'Raw payload preserved');
assert(!str_contains($xssResult['data']['sanitized'], '<script>'), 'Sanitized output must neutralize <script>');
assert(str_contains($xssResult['data']['sanitized'], '&lt;script&gt;'), 'Sanitized output must convert < to &lt;');
assert(isset($xssResult['data']['cipher']), 'Cipher metadata must be present');
assert(isset($xssResult['data']['session_fp']), 'Session fingerprint must be present');
assert($xssResult['toast']['type'] === 'success', 'Toast notification must be type success');

// Test with SQL Injection Union string
$sqliInput = [
    'test_payload' => "' UNION SELECT 1, username, password FROM users --",
    'category'     => 'SQL Injection Probe',
];

$sqliResult = $testSecurityHandler($sqliInput, null);
assert($sqliResult['success'] === true, 'SQLi probe execution failed');
assert(str_contains($sqliResult['data']['sanitized'], '&#039;') || str_contains($sqliResult['data']['sanitized'], '&apos;'), 'Quote must be escaped');

// Test ping Server Action
$pingHandler = ServerAction::get('ping');
assert($pingHandler !== null, 'ping server action must be registered');
$pingResult = $pingHandler([], null);
assert($pingResult['status'] === 'success', 'ping action failed');
assert(is_float($pingResult['memory_mb']) || is_int($pingResult['memory_mb']), 'ping memory_mb must be numeric');
assert($pingResult['toast']['type'] === 'success', 'ping toast must be success');

echo "  -> PASS: testSecurity neutralizes XSS & SQLi payloads with cryptographic telemetry and toasts\n";

// -----------------------------------------------------------------------------
// 4. JSX COMPILATION & VIEW RENDERING
// -----------------------------------------------------------------------------
echo "[4/4] Testing JSX compilation of pages/page.php and pages/layout.php...\n";

$compiler = JsxCompiler::instance();

// Compile page.php
$compiledPageFile = $compiler->getCompiledPath($pageFile);
assert(file_exists($compiledPageFile), 'Compiled page.php must exist in storage/cache/views/');

// Compile layout.php
$compiledLayoutFile = $compiler->getCompiledPath($layoutFile);
assert(file_exists($compiledLayoutFile), 'Compiled layout.php must exist in storage/cache/views/');

// Render page.php
$renderedPage = $compiler->evaluate($compiledPageFile, [
    'appName'   => 'NextPHP Shield v2',
    'csrfToken' => 'test_token_12345',
]);

assert(is_string($renderedPage) && strlen($renderedPage) > 500, 'Rendered page output must be substantial');
assert(str_contains($renderedPage, 'Live Security Defense Grid'), 'Rendered page missing defense grid');
assert(str_contains($renderedPage, 'WAF Application Firewall'), 'Rendered page missing WAF card');
assert(str_contains($renderedPage, 'Interactive Security Probe Demo'), 'Rendered page missing probe demo');
assert(str_contains($renderedPage, 'Shadcn UI Component Registry'), 'Rendered page missing components section');

// Render layout with children
$renderedLayout = $compiler->evaluate($compiledLayoutFile, [
    'children'  => $renderedPage,
    'appName'   => 'NextPHP Shield v2',
    'csrfToken' => 'test_csrf_token_abc',
]);

assert(str_contains($renderedLayout, '<!DOCTYPE html>'), 'Layout rendered HTML must have DOCTYPE');
assert(str_contains($renderedLayout, 'Live Security Defense Grid'), 'Layout must wrap page children');
assert(str_contains($renderedLayout, 'nonce='), 'Layout must render CSP nonce');

echo "  -> PASS: Both page.php and layout.php compile and render cleanly with nested components\n";

echo "\n========================================================\n";
echo "STEP 11 VERIFICATION COMPLETE: ALL CHECKS PASSED (100%)\n";
echo "========================================================\n";
