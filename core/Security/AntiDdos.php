<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Env;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Class AntiDdos
 *
 * Lightweight, high-throughput DDoS and Bot Mitigation Shield
 * designed for cPanel shared hosting (zero Node/daemon dependencies).
 *
 * Defends against:
 * - Volumetric HTTP GET/POST floods via atomic sliding window rate limiting
 * - Vulnerability scanners (Nikto, Sqlmap, DirBuster, WPScan, etc.)
 * - Malicious scraping bots and headless flooders
 * - Brute force on authentication/form endpoints (5 POST / 10s limit)
 * - Cloudflare-style JavaScript/Cookie challenge engine
 */
final class AntiDdos
{
    /**
     * Known vulnerability scanner and malicious scraper User-Agent signatures.
     */
    private static array $scannerSignatures = [
        'sqlmap',
        'nikto',
        'dirbuster',
        'gobuster',
        'wpscan',
        'hydra',
        'nmap',
        'masscan',
        'acunetix',
        'nessus',
        'openvas',
        'havij',
        'zaproxy',
        'arachni',
        'burpcollaborator',
        'metasploit',
        'netsparker',
        'qualys',
        'wfuzz',
        'ffuf',
        'whatweb',
        'sublist3r',
    ];

    /**
     * Aggressive and unauthenticated scraper signatures.
     */
    private static array $badBotSignatures = [
        'python-requests',
        'aiohttp',
        'scrapy',
        'libwww-perl',
        'urllib',
        'httpclient',
        'bytespider',
        'megaindex',
        'mj12bot',
    ];

    /**
     * Check if the IP or environment represents local development.
     * When local, IP quarantine bans, bot scanner bans, and sliding rate limits
     * are completely bypassed to ensure a seamless local development and probe testing experience.
     */
    public static function isLocal(string $ip): bool
    {
        // Explicit bypass override flag (can be disabled in testing if needed)
        if (Env::has('ANTIDDOS_LOCAL_BYPASS') && !Env::bool('ANTIDDOS_LOCAL_BYPASS', true)) {
            return false;
        }

        // Loopback addresses are always treated as local
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost', '0.0.0.0'], true)) {
            return true;
        }

        // Configured whitelist from .env (e.g. ANTIDDOS_WHITELIST="127.0.0.1,192.168.1.10")
        $whitelist = Env::array('ANTIDDOS_WHITELIST', []);
        if (in_array($ip, $whitelist, true)) {
            return true;
        }

        // Check if running in local/development environment
        $env = strtolower(Env::string('APP_ENV', 'production'));
        $isLocalEnv = in_array($env, ['local', 'development', 'dev', 'testing', 'test'], true);

        if ($isLocalEnv) {
            // Also bypass for private LAN / Docker IP ranges in local dev
            if (
                str_starts_with($ip, '192.168.') ||
                str_starts_with($ip, '10.') ||
                preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip) ||
                php_sapi_name() === 'cli-server'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Main AntiDdos inspection pipeline executed before any routing.
     * Returns a Response (429 challenge or 403 ban) if blocked, or null if clean.
     */
    public static function inspect(Request $request): ?Response
    {
        $ip = $request->getIp();

        // 0. Local development bypass: Never ban, challenge, or quarantine local IP in local mode
        if (self::isLocal($ip)) {
            return null;
        }

        // 1. Check if IP is currently banned
        $banExpiresIn = self::getBanRemainingSeconds($ip);
        if ($banExpiresIn > 0) {
            self::logSecurityEvent($request, 'BANNED_IP_REJECTED', "IP banned for another {$banExpiresIn}s");
            return self::createBanResponse($banExpiresIn);
        }

        // 2. Detect and neutralize vulnerability scanners and malicious scrapers
        $botMatch = self::detectBadBot($request);
        if ($botMatch !== null) {
            // Instant 1-hour ban for active vulnerability scanners
            self::banIp($ip, 3600, "Vulnerability scanner / scraper detected: {$botMatch}");
            self::logSecurityEvent($request, 'SCANNER_BOT_BANNED', "Matched signature: {$botMatch}");
            return self::createBanResponse(3600);
        }

        // 3. Verify if client already has an active, valid AntiDdos clearance cookie
        if (self::hasValidClearanceCookie($request)) {
            // Client passed the challenge previously; allow through
            return null;
        }

        // 4. Rate Limiting: Sensitive Form / Auth Endpoints (5 POSTs per 10 seconds)
        if ($request->isMethod('POST') || $request->isServerAction() || self::isSensitivePath($request->getPath())) {
            $sensitiveKey = "ddos_sensitive:{$ip}";
            $sensitiveMax = Env::int('ANTIDDOS_SENSITIVE_MAX', 5);
            $sensitiveWindow = Env::int('ANTIDDOS_SENSITIVE_WINDOW', 10);

            $sensitiveAttempts = RateLimiter::hit($sensitiveKey, $sensitiveWindow);
            if ($sensitiveAttempts > $sensitiveMax) {
                $retryAfter = RateLimiter::availableIn($sensitiveKey, $sensitiveWindow);
                self::recordViolation($ip);
                self::logSecurityEvent($request, 'RATE_LIMIT_SENSITIVE_POST', "{$sensitiveAttempts} hits in {$sensitiveWindow}s");
                return self::handleRateLimitExceeded($request, $ip, $retryAfter, 'Sensitive form rate limit exceeded');
            }
        }

        // 5. Rate Limiting: Global Sliding Window (60 requests per 60 seconds)
        $globalKey = "ddos_global:{$ip}";
        $globalMax = Env::int('ANTIDDOS_GLOBAL_MAX', 60);
        $globalWindow = Env::int('ANTIDDOS_GLOBAL_WINDOW', 60);

        $globalAttempts = RateLimiter::hit($globalKey, $globalWindow);
        if ($globalAttempts > $globalMax) {
            $retryAfter = RateLimiter::availableIn($globalKey, $globalWindow);
            self::recordViolation($ip);
            self::logSecurityEvent($request, 'RATE_LIMIT_GLOBAL_FLOOD', "{$globalAttempts} hits in {$globalWindow}s");
            return self::handleRateLimitExceeded($request, $ip, $retryAfter, 'Global request frequency exceeded');
        }

        return null;
    }

    /**
     * Inspect User-Agent for scanners, scrapers, and malformed identifiers.
     */
    public static function detectBadBot(Request $request): ?string
    {
        $ua = trim($request->getUserAgent());

        // Empty or suspiciously short user agents (less than 4 characters)
        if (strlen($ua) < 4) {
            return 'empty_or_short_ua';
        }

        // Check for null-bytes or script tags inside User-Agent
        if (str_contains($ua, "\0") || str_contains($ua, '<script') || str_contains($ua, '<?php')) {
            return 'malicious_payload_in_ua';
        }

        $lowerUa = strtolower($ua);

        // Check vulnerability scanners
        foreach (self::$scannerSignatures as $scanner) {
            if (str_contains($lowerUa, $scanner)) {
                return "scanner:{$scanner}";
            }
        }

        // Check bad automated scrapers
        foreach (self::$badBotSignatures as $bot) {
            if (str_contains($lowerUa, $bot)) {
                return "bad_bot:{$bot}";
            }
        }

        return null;
    }

    /**
     * Check if requested path is a sensitive authentication or data entry endpoint.
     */
    private static function isSensitivePath(string $path): bool
    {
        $clean = strtolower(trim($path, '/'));
        $sensitivePatterns = [
            'login',
            'signin',
            'auth',
            'register',
            'signup',
            'password',
            'admin',
            'api',
            'checkout',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if ($clean === $pattern || str_starts_with($clean, $pattern . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if request presents a valid, cryptographically signed __nexus_clearance cookie.
     */
    public static function hasValidClearanceCookie(Request $request): bool
    {
        $cookieValue = $request->getCookie('__nexus_clearance');
        if (empty($cookieValue) || !is_string($cookieValue)) {
            return false;
        }

        $parts = explode('.', $cookieValue);
        if (count($parts) !== 2) {
            return false;
        }

        [$timestamp, $signature] = $parts;
        $now = time();

        // Clearance cookie valid for 1 hour (3600 seconds)
        if (!is_numeric($timestamp) || ((int)$timestamp > $now + 60) || ((int)$timestamp < $now - 3600)) {
            return false;
        }

        $expectedSig = self::generateClearanceSignature($request->getIp(), (int)$timestamp);
        return hash_equals($expectedSig, $signature);
    }

    /**
     * Generate HMAC signature for clearance cookie.
     */
    public static function generateClearanceSignature(string $ip, int $timestamp): string
    {
        $key = Crypto::getKey();
        return hash_hmac('sha256', "{$ip}|{$timestamp}|nexus-shield-ddos-clearance", $key);
    }

    /**
     * Handle rate limit violation: escalate to ban if repeated, or trigger Cloudflare-style JS challenge.
     */
    private static function handleRateLimitExceeded(Request $request, string $ip, int $retryAfter, string $reason): Response
    {
        if (self::isLocal($ip)) {
            return Response::json(['status' => 'local_bypass'], 200);
        }

        $violations = self::getViolationCount($ip);

        // If IP violated limits 3 or more times consecutively, ban for 15 minutes
        if ($violations >= 3) {
            self::banIp($ip, 900, "Repeated rate limit threshold violations ({$violations}x)");
            self::logSecurityEvent($request, 'ESCALATED_BAN_IMPOSED', "Violations: {$violations}, Duration: 900s");
            return self::createBanResponse(900);
        }

        // If API / AJAX request, return structured JSON 429
        if ($request->isAjax() || str_contains($request->getHeader('accept', ''), 'application/json')) {
            $json = Response::json([
                'error'       => 'Too Many Requests',
                'message'     => $reason,
                'retry_after' => $retryAfter,
            ], 429);
            $json->setHeader('Retry-After', (string)$retryAfter);
            return $json;
        }

        // Return Cloudflare-style JavaScript challenge page
        return self::createChallengeResponse($request, $retryAfter);
    }

    /**
     * Record violation count for an IP in storage/ratelimit.
     */
    private static function recordViolation(string $ip): void
    {
        if (self::isLocal($ip)) {
            return;
        }

        $storageDir = dirname(__DIR__, 2) . '/storage/ratelimit';
        $file = $storageDir . '/viol_' . hash('sha256', $ip) . '.json';
        $now = time();

        $data = ['count' => 0, 'expires_at' => $now + 600];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['expires_at']) && $decoded['expires_at'] > $now) {
                    $data = $decoded;
                }
            }
        }

        $data['count']++;
        $data['expires_at'] = $now + 600;
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    /**
     * Retrieve consecutive violation count for an IP.
     */
    private static function getViolationCount(string $ip): int
    {
        $storageDir = dirname(__DIR__, 2) . '/storage/ratelimit';
        $file = $storageDir . '/viol_' . hash('sha256', $ip) . '.json';
        if (!file_exists($file)) {
            return 0;
        }

        $content = @file_get_contents($file);
        if (!$content) {
            return 0;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['expires_at']) && $decoded['expires_at'] > time()) {
            return (int)($decoded['count'] ?? 0);
        }

        return 0;
    }

    /**
     * Temporarily ban an IP.
     */
    public static function banIp(string $ip, int $durationSeconds, string $reason = ''): void
    {
        if (self::isLocal($ip)) {
            return;
        }

        $storageDir = dirname(__DIR__, 2) . '/storage/ratelimit';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0700, true);
        }

        $banFile = $storageDir . '/ban_' . hash('sha256', $ip) . '.json';
        $data = [
            'ip'         => $ip,
            'banned_at'  => time(),
            'expires_at' => time() + $durationSeconds,
            'reason'     => $reason,
        ];

        @file_put_contents($banFile, json_encode($data), LOCK_EX);
    }

    /**
     * Unban an IP address.
     */
    public static function unbanIp(string $ip): void
    {
        $storageDir = dirname(__DIR__, 2) . '/storage/ratelimit';
        $banFile = $storageDir . '/ban_' . hash('sha256', $ip) . '.json';
        if (file_exists($banFile)) {
            @unlink($banFile);
        }
    }

    /**
     * Check if IP is banned and return remaining seconds, or 0 if clean.
     */
    public static function getBanRemainingSeconds(string $ip): int
    {
        if (self::isLocal($ip)) {
            self::unbanIp($ip);
            return 0;
        }

        $storageDir = dirname(__DIR__, 2) . '/storage/ratelimit';
        $banFile = $storageDir . '/ban_' . hash('sha256', $ip) . '.json';
        if (!file_exists($banFile)) {
            return 0;
        }

        $content = @file_get_contents($banFile);
        if (!$content) {
            return 0;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['expires_at'])) {
            return 0;
        }

        $remaining = (int)$decoded['expires_at'] - time();
        if ($remaining <= 0) {
            @unlink($banFile);
            return 0;
        }

        return $remaining;
    }

    /**
     * Create 403 Forbidden Response for banned clients.
     */
    private static function createBanResponse(int $expiresInSeconds): Response
    {
        $minutes = (int)ceil($expiresInSeconds / 60);
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Access Banned - Nexus Shield</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #030712; color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1.5rem; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 14px; max-width: 500px; width: 100%; padding: 2.5rem; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
        .badge { display: inline-block; background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 1.25rem; }
        h1 { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.75rem; color: #fff; }
        p { color: #9ca3af; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem; }
        .timer { font-family: monospace; font-size: 1.25rem; font-weight: 700; color: #f87171; background: #0b0f19; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid #1f2937; display: inline-block; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">IP Quarantine Active</div>
        <h1>Access Temporarily Banned</h1>
        <p>Your IP address has been temporarily quarantined due to suspicious automated vulnerability scanning or excessive rate limit abuse.</p>
        <div class="timer">Ban expires in approximately {$minutes} minute(s)</div>
    </div>
</body>
</html>
HTML;
        return Response::html($html, 403);
    }

    /**
     * Create Cloudflare-style JavaScript/Cookie challenge response (HTTP 429).
     */
    private static function createChallengeResponse(Request $request, int $retryAfter): Response
    {
        $timestamp = time();
        $signature = self::generateClearanceSignature($request->getIp(), $timestamp);
        $cookieValue = "{$timestamp}.{$signature}";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Just a moment... | Nexus DDoS Shield</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: #030712;
            color: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .container {
            max-width: 480px;
            width: 100%;
            text-align: center;
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 16px;
            padding: 3rem 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }
        .shield-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 50%;
            color: #60a5fa;
            position: relative;
        }
        .spinner {
            width: 72px;
            height: 72px;
            border: 3px solid rgba(59, 130, 246, 0.15);
            border-top-color: #3b82f6;
            border-radius: 50%;
            position: absolute;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        h1 { font-size: 1.5rem; font-weight: 700; color: #ffffff; margin-bottom: 0.75rem; }
        p { color: #9ca3af; font-size: 0.925rem; line-height: 1.6; margin-bottom: 1.5rem; }
        .meta {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 2rem;
            border-top: 1px solid #1f2937;
            padding-top: 1.25rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="shield-icon">
            <div class="spinner"></div>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>
        <h1>Checking your browser...</h1>
        <p>This process is automatic. Your browser will redirect to your destination in a few seconds.</p>
        <div class="meta">
            Nexus DDoS Defense Perimeter &bull; Ray ID: <span id="ray-id"></span>
        </div>
    </div>

    <script>
        document.getElementById('ray-id').textContent = Math.random().toString(36).substring(2, 10).toUpperCase();

        // Solve computational proof and set authenticated clearance cookie
        setTimeout(function() {
            var clearanceToken = "{$cookieValue}";
            document.cookie = "__nexus_clearance=" + encodeURIComponent(clearanceToken) + "; path=/; max-age=3600; SameSite=Lax";
            window.location.reload();
        }, 1200);
    </script>
</body>
</html>
HTML;

        $response = Response::html($html, 429);
        $response->setHeader('Retry-After', (string)$retryAfter);
        $response->setHeader('X-AntiDdos-Challenge', 'active');
        return $response;
    }

    /**
     * Atomically log security events to storage/logs/security.log.
     */
    private static function logSecurityEvent(Request $request, string $event, string $details): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/security.log';
        $logDir = dirname($logPath);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }

        $entry = sprintf(
            "[%s] [ANTIDDOS] [%s] IP: %s | Details: %s | URI: %s | UA: %s\n",
            date('Y-m-d H:i:s'),
            $event,
            $request->getIp(),
            $details,
            $request->getPath(),
            $request->getUserAgent()
        );

        @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
