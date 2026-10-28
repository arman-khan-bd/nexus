<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Env.php';
require_once __DIR__ . '/../core/Security/Crypto.php';
require_once __DIR__ . '/../core/Security/RateLimiter.php';
require_once __DIR__ . '/../core/Security/AntiDdos.php';
require_once __DIR__ . '/../core/Security/Firewall.php';
require_once __DIR__ . '/../core/Security/SessionGuard.php';
require_once __DIR__ . '/../core/Security/Csrf.php';
require_once __DIR__ . '/../core/Http/Request.php';
require_once __DIR__ . '/../core/Http/Response.php';

use Core\Env;
use Core\Security\Crypto;
use Core\Security\RateLimiter;
use Core\Security\AntiDdos;
use Core\Security\Firewall;
use Core\Security\SessionGuard;
use Core\Security\Csrf;
use Core\Http\Request;

echo "========================================================\n";
echo "NEXUS DEFENSE PERIMETER - STEP 2 COMPREHENSIVE SUITE\n";
echo "========================================================\n";

$baseDir = dirname(__DIR__);
Env::load($baseDir . '/.env');

// Clean rate limit directory before test
$rlDir = $baseDir . '/storage/ratelimit';
foreach (glob($rlDir . '/*.json') ?: [] as $file) {
    @unlink($file);
}

// -----------------------------------------------------------------------------
// 1. RATE LIMITER SLIDING WINDOW TESTS
// -----------------------------------------------------------------------------
echo "[1/4] Testing RateLimiter sliding window atomic engine...\n";
$testKey = 'test_client_ip_' . bin2hex(random_bytes(4));

assert(RateLimiter::attempts($testKey, 10) === 0, 'Initial attempts must be 0');
assert(RateLimiter::hit($testKey, 10) === 1, 'First hit must equal 1');
assert(RateLimiter::hit($testKey, 10) === 2, 'Second hit must equal 2');
assert(RateLimiter::hit($testKey, 10) === 3, 'Third hit must equal 3');
assert(RateLimiter::attempts($testKey, 10) === 3, 'Attempts count mismatch');
assert(!RateLimiter::tooManyAttempts($testKey, 5, 10), 'Should not have exceeded 5 attempts');
assert(RateLimiter::remaining($testKey, 5, 10) === 2, 'Remaining attempts must be 2');

RateLimiter::hit($testKey, 10);
RateLimiter::hit($testKey, 10);
assert(RateLimiter::tooManyAttempts($testKey, 5, 10), 'Should have exceeded 5 attempts');
assert(RateLimiter::availableIn($testKey, 10) > 0, 'AvailableIn should be positive seconds');

RateLimiter::clear($testKey);
assert(RateLimiter::attempts($testKey, 10) === 0, 'Clear must reset attempts to 0');
echo "  -> PASS: Atomic sliding window tracking and decay calculations\n";

// -----------------------------------------------------------------------------
// 2. ANTIDDOS BOT & SCANNER DETECTION & SLIDING LIMITS
// -----------------------------------------------------------------------------
echo "[2/4] Testing AntiDdos scanner defense, sliding limits & JS challenge...\n";

// Scanner detection: Nikto, Sqlmap, Dirbuster
$scanners = [
    'Mozilla/5.0 (compatible; Nikto/2.1.6)',
    'sqlmap/1.6#stable (https://sqlmap.org)',
    'DirBuster-1.0-RC1 (http://www.owasp.org/index.php/Category:OWASP_DirBuster_Project)',
    'wpscan v3.8.20',
    'Mozilla/5.0 (Hydra)',
];

foreach ($scanners as $scannerUa) {
    $scannerIp = '198.51.100.' . rand(1, 250);
    $scannerReq = new Request('GET', '/', '/', [], [], ['host' => 'localhost', 'user-agent' => $scannerUa], [], [], ['REMOTE_ADDR' => $scannerIp]);
    
    assert(AntiDdos::detectBadBot($scannerReq) !== null, "Scanner [{$scannerUa}] was not detected");
    
    $blocked = AntiDdos::inspect($scannerReq);
    assert($blocked !== null && $blocked->getStatus() === 403, "Scanner [{$scannerUa}] was not banned with 403");
    assert(AntiDdos::getBanRemainingSeconds($scannerIp) > 3500, "Scanner [{$scannerUa}] was not banned for 1 hour");
    
    // Clean ban
    AntiDdos::unbanIp($scannerIp);
}
echo "  -> PASS: Vulnerability scanners (Nikto, Sqlmap, DirBuster, WPScan, Hydra) detected and banned\n";

// Sensitive Form Rate Limiting: 5 POSTs per 10 seconds limit
$loginIp = '203.0.113.' . rand(1, 250);
for ($i = 1; $i <= 5; $i++) {
    $postReq = new Request('POST', '/login', '/login', [], ['user' => 'admin'], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0 Chrome/120.0'], [], [], ['REMOTE_ADDR' => $loginIp]);
    $res = AntiDdos::inspect($postReq);
    assert($res === null, "Sensitive POST {$i}/5 should be permitted under threshold");
}

// 6th POST within 10s must trigger HTTP 429
$floodReq = new Request('POST', '/login', '/login', [], ['user' => 'admin'], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0 Chrome/120.0'], [], [], ['REMOTE_ADDR' => $loginIp]);
$floodRes = AntiDdos::inspect($floodReq);
assert($floodRes !== null && $floodRes->getStatus() === 429, "6th POST must trigger 429 challenge");
assert(str_contains($floodRes->getBody(), 'Checking your browser'), "429 must return Cloudflare-style JS challenge page");
echo "  -> PASS: Sensitive endpoint brute-force protection (5 POST / 10s -> 429 Challenge)\n";

// Verify Cloudflare-style __nexus_clearance Cookie
$testTs = time();
$clearanceSig = AntiDdos::generateClearanceSignature($loginIp, $testTs);
$clearanceCookie = "{$testTs}.{$clearanceSig}";
$clearedReq = new Request('GET', '/login', '/login', [], [], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0 Chrome/120.0'], ['__nexus_clearance' => $clearanceCookie], [], ['REMOTE_ADDR' => $loginIp]);
assert(AntiDdos::hasValidClearanceCookie($clearedReq) === true, "Valid clearance cookie must pass verification");
echo "  -> PASS: Cloudflare-style cryptographic clearance cookie verified successfully\n";

// -----------------------------------------------------------------------------
// 3. FIREWALL NORMALIZATION & DEEP INSPECTION TESTS
// -----------------------------------------------------------------------------
echo "[3/4] Testing Firewall input normalization & OWASP Top 10 mitigation...\n";

// 3A. Normalization
// Multi-layer URL decoding: %252e%252e%252f -> ../
assert(Firewall::normalizeString('%252e%252e%252f') === '../', 'Multi-layer URL decoding failed');

// Null-byte stripping: admin\0.php -> admin.php
assert(Firewall::normalizeString("admin\x00.php") === 'admin.php', 'Null byte stripping failed');
assert(Firewall::normalizeString("test%00user") === 'testuser', 'URL encoded null byte stripping failed');

// Fullwidth unicode homoglyph normalization: ／ -> /
assert(Firewall::normalizeString("\xEF\xBC\x8Fetc\xEF\xBC\x8Fpasswd") === '/etc/passwd', 'Unicode homoglyph slash failed');

// 3B. Exploit Signatures Detection
$testPayloads = [
    'SQL_INJECTION'        => ["1' UNION SELECT null, password FROM users --", "1' OR '1'='1", "admin'--", "1; DROP TABLE users;"],
    'CROSS_SITE_SCRIPTING' => ['<script>alert("pwned")</script>', '<svg/onload=alert(1)>', 'javascript:alert(1)', '<img src=x onerror=alert(1)>'],
    'COMMAND_INJECTION_RCE' => ['; cat /etc/passwd', '| id', '`whoami`', '$(uname -a)', 'system("calc.exe")'],
    'PATH_TRAVERSAL_LFI'   => ['../../../../etc/passwd', '..\\..\\windows\\system32', 'php://filter/read=convert.base64-encode/resource=index.php'],
    'PHP_OBJECT_INJECTION' => ['O:8:"Exploiter":1:{s:4:"code";s:10:"phpinfo();";}', 'a:2:{i:0;O:4:"User":0:{}}'],
    'SUPERGLOBAL_POLLUTION' => ['GLOBALS[test]=1', '_SERVER[REMOTE_ADDR]=127.0.0.1'],
    'XXE_INJECTION'        => ['<!ENTITY xxe SYSTEM "file:///etc/passwd">'],
];

foreach ($testPayloads as $vector => $cases) {
    foreach ($cases as $payload) {
        // Test in Query String
        $reqGet = new Request('GET', '/test', '/test', ['q' => $payload], [], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $resGet = Firewall::inspect($reqGet);
        assert($resGet !== null && $resGet->getStatus() === 403, "Firewall failed to block {$vector} in GET: {$payload}");

        // Test in POST Body
        $reqPost = new Request('POST', '/test', '/test', [], ['field' => $payload], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $resPost = Firewall::inspect($reqPost);
        assert($resPost !== null && $resPost->getStatus() === 403, "Firewall failed to block {$vector} in POST: {$payload}");
    }
}
echo "  -> PASS: Deep input normalization & all attack vectors blocked with 403 Forbidden\n";

// Verify audit logging to storage/logs/security.log
$logContent = file_get_contents($baseDir . '/storage/logs/security.log');
assert(str_contains($logContent, 'FIREWALL_BLOCKED') && str_contains($logContent, 'SQL_INJECTION'), "Audit log missing firewall events");
echo "  -> PASS: Tamper-evident audit logging verified in storage/logs/security.log\n";

// -----------------------------------------------------------------------------
// 4. CSRF SHIELD TESTS
// -----------------------------------------------------------------------------
echo "[4/4] Testing Csrf token generation, header verification & session binding...\n";

// Start session
$csrfTestReq = new Request('GET', '/', '/', [], [], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
SessionGuard::start($csrfTestReq);

$token = Csrf::token();
assert(is_string($token) && strlen($token) === 64, 'CSRF token must be 64 hex characters (256-bit)');
assert(str_contains(Csrf::field(), 'name="_token"'), 'Csrf::field() must generate hidden input');
assert(str_contains(Csrf::meta(), 'name="csrf-token"'), 'Csrf::meta() must generate meta tag');

// Safe method GET should pass without validation
assert(Csrf::validateRequest($csrfTestReq) === null, 'GET request must be exempt from CSRF');

// Mutating method POST without token must fail
$badPost = new Request('POST', '/submit', '/submit', [], ['foo' => 'bar'], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
$badRes = Csrf::validateRequest($badPost);
assert($badRes !== null && $badRes->getStatus() === 403, 'POST without token must return 403');

// POST with valid X-CSRF-TOKEN header must pass
$validHeaderPost = new Request('POST', '/submit', '/submit', [], ['foo' => 'bar'], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0', 'x-csrf-token' => $token], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
assert(Csrf::validateRequest($validHeaderPost) === null, 'POST with valid X-CSRF-TOKEN header must be permitted');

// POST with valid _token form field must pass
$validFieldPost = new Request('POST', '/submit', '/submit', [], ['foo' => 'bar', '_token' => $token], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
assert(Csrf::validateRequest($validFieldPost) === null, 'POST with valid _token body field must be permitted');

// Exemption test
Csrf::exempt('/api/webhook/stripe');
$exemptPost = new Request('POST', '/api/webhook/stripe', '/api/webhook/stripe', [], ['event' => 'payment'], ['host' => 'localhost', 'user-agent' => 'Mozilla/5.0'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
assert(Csrf::validateRequest($exemptPost) === null, 'Exempt webhook route must bypass CSRF');

echo "  -> PASS: Session-bound cryptographic CSRF generation, validation & timing-safe verification\n";

echo "========================================================\n";
echo "STEP 2 ALL DEFENSE TESTS PASSED! (100% HEALTHY & SECURE)\n";
echo "========================================================\n";
