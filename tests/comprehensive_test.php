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
use Core\Security\Waf;
use Core\Security\SessionGuard;
use Core\Http\Request;
use Core\Router\FileSystemRouter;

echo "===========================================\n";
echo "NEXUS FRAMEWORK - STEP 1 COMPREHENSIVE SUITE\n";
echo "===========================================\n";

$baseDir = dirname(__DIR__);
Env::load($baseDir . '/.env');

// Test 1: Env Loading & Typed Getters
assert(Env::string('APP_NAME') === 'Nexus Application', 'Env::string failed');
assert(Env::bool('APP_DEBUG') === true, 'Env::bool failed');
assert(Env::int('DB_PORT') === 3306, 'Env::int failed');
assert(is_string(Env::validateAppKey()), 'Env::validateAppKey failed');
assert(strlen(Env::validateAppKey()) === 32, 'Env::validateAppKey byte length not 32');
echo "[PASS] Env: Strict typed parsing & 256-bit APP_KEY validation\n";

// Test 2: Crypto Encrypt / Decrypt
$secret = "Nexus Ultra Secure Payload 2026";
$encrypted = Crypto::encrypt($secret);
$decrypted = Crypto::decrypt($encrypted);
assert($decrypted === $secret, 'Crypto encrypt/decrypt failed');
assert(Crypto::verifyHmac($secret, Crypto::hmac($secret)), 'Crypto HMAC failed');
echo "[PASS] Crypto: Authenticated encryption & HMAC verification\n";

// Test 3: WAF Attack Interception
$cleanReq = new Request('GET', '/about', '/about', [], [], ['host' => 'localhost'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
assert(Waf::inspect($cleanReq) === null, 'WAF falsely flagged clean request');

// SQL Injection attack attempt
$sqliReq = new Request('GET', '/posts?id=1%20UNION%20SELECT%20password%20FROM%20users', '/posts', ['id' => "1' UNION SELECT password FROM users"], [], ['host' => 'localhost'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
$sqliBlocked = Waf::inspect($sqliReq);
assert($sqliBlocked !== null && $sqliBlocked->getStatus() === 403, 'WAF failed to block SQLi');

// XSS attack attempt
$xssReq = new Request('GET', '/search?q=<script>alert(1)</script>', '/search', ['q' => '<script>alert(1)</script>'], [], ['host' => 'localhost'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
$xssBlocked = Waf::inspect($xssReq);
assert($xssBlocked !== null && $xssBlocked->getStatus() === 403, 'WAF failed to block XSS');

// Path traversal attack attempt
$traversalReq = new Request('GET', '/view?file=../../../../etc/passwd', '/view', ['file' => '../../../../etc/passwd'], [], ['host' => 'localhost'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
$traversalBlocked = Waf::inspect($traversalReq);
assert($traversalBlocked !== null && $traversalBlocked->getStatus() === 403, 'WAF failed to block Path Traversal');
echo "[PASS] WAF: OWASP Top 10 attack detection (SQLi, XSS, Path Traversal -> 403 Forbidden)\n";

// Test 4: FileSystemRouter
$router = new FileSystemRouter($baseDir . '/pages');

// Test Home Route
$homeReq = new Request('GET', '/', '/', [], [], ['host' => 'localhost'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
$homeRes = $router->dispatch($homeReq);
assert($homeRes->getStatus() === 200, 'Home route returned non-200');
assert(str_contains($homeRes->getBody(), 'Ultra-Secure PHP Framework'), 'Home content missing');

// Test About Route
$aboutReq = new Request('GET', '/about', '/about', [], [], ['host' => 'localhost'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
$aboutRes = $router->dispatch($aboutReq);
assert($aboutRes->getStatus() === 200, 'About route returned non-200');
assert(str_contains($aboutRes->getBody(), 'System Architecture &amp; Security Specs'), 'About content missing');

// Test 404 Route
$missingReq = new Request('GET', '/non-existent-page', '/non-existent-page', [], [], ['host' => 'localhost'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
$missingRes = $router->dispatch($missingReq);
assert($missingRes->getStatus() === 404, '404 route failed');
echo "[PASS] FileSystemRouter: Next.js App Router hierarchy, layouts & 404 resolution\n";

// Test 5: Server Actions
SessionGuard::start($cleanReq);
$csrfToken = SessionGuard::csrfToken();

$actionReq = new Request(
    'POST',
    '/',
    '/',
    [],
    ['_action' => 'ping', '_token' => $csrfToken],
    ['host' => 'localhost', 'x-nexus-action' => 'ping', 'x-csrf-token' => $csrfToken],
    [],
    [],
    ['REMOTE_ADDR' => '127.0.0.1'],
    json_encode(['_action' => 'ping', '_token' => $csrfToken])
);

$actionRes = $router->dispatch($actionReq);
assert($actionRes->getStatus() === 200, 'Server Action failed');
$actionData = json_decode($actionRes->getBody(), true);
assert(isset($actionData['status']) && $actionData['status'] === 'success', 'Server Action payload mismatch');
assert(str_contains($actionData['message'], 'Server Action executed securely'), 'Action message missing');
echo "[PASS] Server Actions: Zero-boilerplate PHP action executed with CSRF protection\n";

echo "===========================================\n";
echo "ALL TESTS PASSED SUCCESSFULLY! (100% HEALTHY)\n";
echo "===========================================\n";
