<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Env;
use Core\Http\Request;
use SessionHandlerInterface;
use RuntimeException;

/**
 * Class SessionGuard
 *
 * Anti-Hijacking Encrypted Session Manager for shared cPanel hosting.
 * Implements SessionHandlerInterface to store Libsodium/AES-256 authenticated
 * encrypted sessions in storage/sessions with 0700/0600 permission masks.
 *
 * Capabilities:
 * - Libsodium (XChaCha20-Poly1305) / OpenSSL (AES-256-GCM) on-disk session encryption
 * - Subnet (/24 IPv4, /48 IPv6) + Browser User-Agent HMAC session fingerprinting
 * - Immediate session destruction on hijacking detection
 * - Anti-fixation automatic session ID rotation every 10 minutes (600s)
 */
final class SessionGuard implements SessionHandlerInterface
{
    private static bool $isStarted = false;
    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? (dirname(__DIR__, 2) . '/storage/sessions');
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0700, true);
        }
    }

    /**
     * Boot and secure the session engine.
     */
    public static function start(Request $request): void
    {
        if (self::$isStarted || session_status() === PHP_SESSION_ACTIVE) {
            self::$isStarted = true;
            return;
        }

        $sessionDir = dirname(__DIR__, 2) . '/storage/sessions';
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0700, true);
        }

        if (!headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
            // Register encrypted custom session save handler
            $handler = new self($sessionDir);
            @session_set_save_handler($handler, true);

            $lifetime = Env::int('SESSION_LIFETIME', 120) * 60;
            $cookieName = Env::string('SESSION_COOKIE_NAME', 'nexus_session');
            $isSecure = $request->isSecure() || Env::bool('SESSION_SECURE_COOKIE', false);
            $sameSite = Env::string('SESSION_SAME_SITE', 'Lax');

            // Configure hardened session INI directives
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_cookies', '1');
            @ini_set('session.use_only_cookies', '1');
            @ini_set('session.cookie_httponly', '1');
            @ini_set('session.cookie_samesite', $sameSite);
            if ($isSecure) {
                @ini_set('session.cookie_secure', '1');
            }

            @session_name($cookieName);

            @session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => $sameSite,
            ]);

            @session_start();
        } elseif (session_status() !== PHP_SESSION_ACTIVE) {
            $handler = new self($sessionDir);
            @session_set_save_handler($handler, true);
            @session_start();
        }

        self::$isStarted = true;

        // 1. Session Hijacking Verification (Subnet + User-Agent Fingerprint)
        self::verifyFingerprint($request);

        // 2. Anti-Fixation Session ID Rotation (every 10 minutes)
        self::rotateSessionIdPeriodically();

        // 3. Ensure session-bound CSRF token is active
        Csrf::token();
    }

    /**
     * Generate the session fingerprint hash for the current client request.
     */
    public static function fingerprint(Request $request): string
    {
        $ip = $request->getIp();
        $subnet = self::getIpSubnet($ip);
        $ua = $request->getUserAgent();

        return hash_hmac('sha256', "{$subnet}|{$ua}", Crypto::getKey());
    }

    /**
     * Binds session to client User-Agent and IP Subnet (/24 for IPv4, /48 for IPv6).
     * If session token is stolen and replayed from another network or browser,
     * the session is instantly neutralized and destroyed.
     */
    public static function verifyFingerprint(Request $request): void
    {
        $expectedFingerprint = self::fingerprint($request);

        if (!isset($_SESSION['_nexus_fingerprint'])) {
            $_SESSION['_nexus_fingerprint'] = $expectedFingerprint;
            return;
        }

        if (!hash_equals($_SESSION['_nexus_fingerprint'], $expectedFingerprint)) {
            // Compromise detected: Log and destroy session immediately
            self::logSecurityAlert(
                'SESSION_HIJACK_DETECTED',
                $request,
                "Expected subnet/UA fingerprint did not match active session token."
            );

            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_destroy();
            }

            if (!headers_sent()) {
                @session_start();
                session_regenerate_id(true);
            }

            $_SESSION['_nexus_fingerprint'] = $expectedFingerprint;
            $_SESSION['_nexus_last_rotated'] = time();
            Csrf::regenerateToken();
        }
    }

    /**
     * Compute /24 subnet for IPv4 or /48 for IPv6 to accommodate mobile ISP IP jumps.
     */
    public static function getIpSubnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 3)) . '::';
        }

        return $ip;
    }

    /**
     * Automatic session ID rotation every 10 minutes (600 seconds) to mitigate fixation attacks.
     */
    public static function rotateSessionIdPeriodically(): void
    {
        $now = time();
        $rotateInterval = 600; // 10 minutes

        if (!isset($_SESSION['_nexus_last_rotated'])) {
            $_SESSION['_nexus_last_rotated'] = $now;
            return;
        }

        if ($now - (int)$_SESSION['_nexus_last_rotated'] >= $rotateInterval) {
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                @session_regenerate_id(true);
            }
            $_SESSION['_nexus_last_rotated'] = $now;
        }
    }

    // -------------------------------------------------------------------------
    // SessionHandlerInterface Implementation (Encrypted Storage)
    // -------------------------------------------------------------------------

    public function open(string $path, string $name): bool
    {
        return is_dir($this->storagePath) && is_writable($this->storagePath);
    }

    public function close(): bool
    {
        return true;
    }

    /**
     * Read and decrypt session data from disk.
     */
    public function read(string $id): string|false
    {
        $filePath = $this->getSessionFilePath($id);
        if (!file_exists($filePath)) {
            return '';
        }

        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            return '';
        }

        $rawCiphertext = '';
        try {
            if (flock($fp, LOCK_SH)) {
                $rawCiphertext = (string)stream_get_contents($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        if ($rawCiphertext === '') {
            return '';
        }

        try {
            // Decrypt using authenticated 256-bit Libsodium/AES cipher
            return Crypto::decrypt($rawCiphertext);
        } catch (\Throwable $e) {
            // Data corrupted or tampered by malicious multi-tenant actor on shared hosting
            self::logSecurityAlert(
                'SESSION_DECRYPTION_FAILED',
                null,
                "Session [{$id}] decryption failed: " . $e->getMessage()
            );
            @unlink($filePath);
            return '';
        }
    }

    /**
     * Encrypt and atomically write session data to disk.
     */
    public function write(string $id, string $data): bool
    {
        $filePath = $this->getSessionFilePath($id);

        try {
            // Authenticated encryption of serialized session state
            $encryptedPayload = Crypto::encrypt($data);
        } catch (\Throwable $e) {
            return false;
        }

        $fp = fopen($filePath, 'c+');
        if ($fp === false) {
            return false;
        }

        $success = false;
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $encryptedPayload);
                fflush($fp);
                flock($fp, LOCK_UN);
                $success = true;
            }
        } finally {
            fclose($fp);
        }

        if ($success && DIRECTORY_SEPARATOR !== '\\') {
            @chmod($filePath, 0600);
        }

        return $success;
    }

    /**
     * Destroy session file on disk.
     */
    public function destroy(string $id): bool
    {
        $filePath = $this->getSessionFilePath($id);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        return true;
    }

    /**
     * Garbage collection: Clean up expired session files.
     */
    public function gc(int $max_lifetime): int|false
    {
        $threshold = time() - $max_lifetime;
        $deleted = 0;
        $files = glob($this->storagePath . '/sess_*');

        if ($files) {
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $threshold) {
                    if (@unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Generate sanitized session file path.
     */
    private function getSessionFilePath(string $id): string
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9,_-]/', '', $id);
        return $this->storagePath . '/sess_' . $cleanId;
    }

    /**
     * Retrieve active CSRF token.
     */
    public static function csrfToken(): string
    {
        return Csrf::token();
    }

    /**
     * Verify CSRF token from header or request body.
     */
    public static function verifyCsrf(Request $request): bool
    {
        return Csrf::verify($request);
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    private static function logSecurityAlert(string $alertType, ?Request $request, string $details): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/security.log';
        $logDir = dirname($logPath);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }

        $ip = $request ? $request->getIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = $request ? $request->getUserAgent() : ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        $entry = sprintf(
            "[%s] [SESSION_GUARD] [%s] IP: %s | UA: %s | Details: %s\n",
            date('Y-m-d H:i:s'),
            $alertType,
            $ip,
            $ua,
            $details
        );

        @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
