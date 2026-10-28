<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use InvalidArgumentException;

/**
 * Class Env
 *
 * High-performance, immutable environment manager for Nexus.
 * Provides strict typing, variable expansion, tamper resistance,
 * and 256-bit Libsodium/AES APP_KEY cryptographic validation.
 */
final class Env
{
    /**
     * Immutable key-value store.
     * Once loaded, this registry cannot be modified.
     *
     * @var array<string, mixed>
     */
    private static array $repository = [];

    /**
     * Whether the environment file has been loaded and locked.
     */
    private static bool $isLoaded = false;

    /**
     * Loaded file path for diagnostics.
     */
    private static ?string $loadedPath = null;

    /**
     * Load environment variables from a file into the immutable repository.
     *
     * @param string $path Path to the .env file.
     * @param bool $overrideExisting Whether to reload/override (restricted).
     * @return void
     * @throws RuntimeException If file cannot be read.
     */
    public static function load(string $path, bool $overrideExisting = false): void
    {
        if (self::$isLoaded && !$overrideExisting) {
            return;
        }

        if (!is_file($path)) {
            // If file does not exist, initialize empty repository or load server/env variables
            self::loadFromSuperglobals();
            self::$isLoaded = true;
            return;
        }

        if (!is_readable($path)) {
            throw new RuntimeException("Environment file at [{$path}] is not readable. Check file permissions.");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("Failed to read environment file at [{$path}].");
        }

        $rawEntries = [];
        $currentKey = null;
        $multilineBuffer = '';
        $isInsideMultiline = false;
        $quoteChar = null;

        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);

            // Ignore comments outside quotes
            if (!$isInsideMultiline && ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//'))) {
                continue;
            }

            if ($isInsideMultiline) {
                // Check if current line ends the multiline string
                if (str_ends_with($trimmed, $quoteChar) && !str_ends_with($trimmed, '\\' . $quoteChar)) {
                    $multilineBuffer .= "\n" . substr($trimmed, 0, -1);
                    $rawEntries[$currentKey] = self::unescapeString($multilineBuffer);
                    $isInsideMultiline = false;
                    $currentKey = null;
                    $multilineBuffer = '';
                    $quoteChar = null;
                } else {
                    $multilineBuffer .= "\n" . $line;
                }
                continue;
            }

            // Split key=val
            $separatorPos = strpos($line, '=');
            if ($separatorPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separatorPos));
            $val = trim(substr($line, $separatorPos + 1));

            // Validate key format (alphanumeric and underscores)
            if (!preg_match('/^[A-Z0-9_]+$/i', $key)) {
                continue;
            }

            // Check if value is quoted
            if (strlen($val) > 0 && ($val[0] === '"' || $val[0] === "'")) {
                $q = $val[0];
                if (strlen($val) > 1 && str_ends_with($val, $q) && !str_ends_with($val, '\\' . $q)) {
                    // Closed on same line
                    $inner = substr($val, 1, -1);
                    $rawEntries[$key] = ($q === '"') ? self::unescapeString($inner) : $inner;
                } else {
                    // Starts multiline
                    $isInsideMultiline = true;
                    $currentKey = $key;
                    $quoteChar = $q;
                    $multilineBuffer = substr($val, 1);
                }
            } else {
                // Strip inline comment if any
                $inlineCommentPos = strpos($val, ' #');
                if ($inlineCommentPos !== false) {
                    $val = trim(substr($val, 0, $inlineCommentPos));
                }
                $rawEntries[$key] = self::parseLiteral($val);
            }
        }

        // Second pass: Variable expansion (e.g. ${APP_URL}/api or $APP_NAME)
        foreach ($rawEntries as $k => $v) {
            if (is_string($v)) {
                $expanded = preg_replace_callback('/\$\{([A-Z0-9_]+)\}|\$([A-Z0-9_]+)/i', function ($matches) use ($rawEntries) {
                    $varName = $matches[1] ?: $matches[2];
                    if (isset($rawEntries[$varName])) {
                        return (string)$rawEntries[$varName];
                    }
                    if (isset($_ENV[$varName])) {
                        return (string)$_ENV[$varName];
                    }
                    if (isset($_SERVER[$varName])) {
                        return (string)$_SERVER[$varName];
                    }
                    return '';
                }, $v);
                $rawEntries[$k] = $expanded;
            }

            // Populate $_ENV for compatibility
            $_ENV[$k] = $rawEntries[$k];
            $_SERVER[$k] = $rawEntries[$k];
        }

        self::$repository = $rawEntries;
        self::$loadedPath = $path;
        self::$isLoaded = true;
    }

    /**
     * Fallback to populate repository from $_ENV and $_SERVER if no .env file is present.
     */
    private static function loadFromSuperglobals(): void
    {
        $merged = array_merge($_SERVER, $_ENV);
        foreach ($merged as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Z0-9_]+$/i', $k)) {
                self::$repository[$k] = is_string($v) ? self::parseLiteral($v) : $v;
            }
        }
    }

    /**
     * Parse raw string literals into booleans, null, or numbers.
     */
    private static function parseLiteral(string $value): mixed
    {
        $lower = strtolower($value);
        if ($lower === 'true' || $lower === '(true)') {
            return true;
        }
        if ($lower === 'false' || $lower === '(false)') {
            return false;
        }
        if ($lower === 'null' || $lower === '(null)' || $lower === 'empty') {
            return null;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }
        return $value;
    }

    /**
     * Unescape quoted string sequences (\n, \r, \t, etc.).
     */
    private static function unescapeString(string $value): string
    {
        return strtr($value, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\v' => "\v",
            '\\f' => "\f",
            '\\"' => '"',
            '\\\\' => '\\',
            '\\$' => '$'
        ]);
    }

    /**
     * Retrieve an environment variable with optional default value.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$repository)) {
            return self::$repository[$key];
        }

        if (array_key_exists($key, $_ENV)) {
            return self::$repository[$key] = self::parseLiteral((string)$_ENV[$key]);
        }

        if (array_key_exists($key, $_SERVER)) {
            return self::$repository[$key] = self::parseLiteral((string)$_SERVER[$key]);
        }

        return $default;
    }

    /**
     * Retrieve an environment variable cast to string.
     */
    public static function string(string $key, string $default = ''): string
    {
        $val = self::get($key, $default);
        if ($val === null) {
            return $default;
        }
        return (string)$val;
    }

    /**
     * Retrieve an environment variable cast to integer.
     */
    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key, $default);
        if ($val === null || $val === '') {
            return $default;
        }
        return (int)$val;
    }

    /**
     * Retrieve an environment variable cast to boolean.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key, $default);
        if ($val === null) {
            return $default;
        }
        if (is_bool($val)) {
            return $val;
        }
        $normalized = strtolower(trim((string)$val));
        return in_array($normalized, ['1', 'true', 'on', 'yes', 'enabled'], true);
    }

    /**
     * Retrieve an environment variable cast to float.
     */
    public static function float(string $key, float $default = 0.0): float
    {
        $val = self::get($key, $default);
        if ($val === null || $val === '') {
            return $default;
        }
        return (float)$val;
    }

    /**
     * Retrieve a comma-separated list or JSON array as an array.
     *
     * @return array<int|string, mixed>
     */
    public static function array(string $key, array $default = []): array
    {
        $val = self::get($key);
        if ($val === null || $val === '') {
            return $default;
        }
        if (is_array($val)) {
            return $val;
        }
        if (is_string($val)) {
            if (str_starts_with($val, '[') && str_ends_with($val, ']')) {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return array_map('trim', explode(',', $val));
        }
        return (array)$val;
    }

    /**
     * Check if an environment variable exists.
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$repository) ||
               array_key_exists($key, $_ENV) ||
               array_key_exists($key, $_SERVER);
    }

    /**
     * Require an environment variable, throwing an exception if not set or empty.
     *
     * @throws RuntimeException
     */
    public static function require(string $key): mixed
    {
        $val = self::get($key);
        if ($val === null || $val === '') {
            throw new RuntimeException("Required environment configuration [{$key}] is missing or empty.");
        }
        return $val;
    }

    /**
     * Validates and returns the 256-bit APP_KEY for Libsodium / AES-256 encryption.
     *
     * Format: 'base64:<32_bytes_base64_encoded>' or raw 32-byte binary string.
     *
     * @return string The raw 32-byte cryptographic key.
     * @throws RuntimeException If key is missing, corrupted, or cryptographically insecure.
     */
    public static function validateAppKey(): string
    {
        $rawKey = self::get('APP_KEY');

        if (empty($rawKey) || !is_string($rawKey)) {
            throw new RuntimeException(
                "NEXUS SECURITY VIOLATION: APP_KEY is missing.\n" .
                "The application cannot safely execute without an authenticated 256-bit key.\n" .
                "Run 'php nexus key:generate' or 'composer run key:generate' to generate a valid key."
            );
        }

        $rawKey = trim($rawKey);

        if (str_starts_with($rawKey, 'base64:')) {
            $base64Data = substr($rawKey, 7);
            $decoded = base64_decode($base64Data, true);

            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException(
                    "NEXUS SECURITY ERROR: APP_KEY base64 format is invalid or does not equal exactly 256 bits (32 bytes). " .
                    "Length received: " . (is_string($decoded) ? strlen($decoded) : 0) . " bytes."
                );
            }

            self::assertNotWeakKey($decoded);
            return $decoded;
        }

        // Check if raw 32 bytes
        if (strlen($rawKey) === 32) {
            self::assertNotWeakKey($rawKey);
            return $rawKey;
        }

        // Check if 64-character hex string
        if (strlen($rawKey) === 64 && ctype_xdigit($rawKey)) {
            $binaryKey = hex2bin($rawKey);
            if ($binaryKey !== false && strlen($binaryKey) === 32) {
                self::assertNotWeakKey($binaryKey);
                return $binaryKey;
            }
        }

        throw new RuntimeException(
            "NEXUS SECURITY ERROR: APP_KEY must be a 256-bit key prefixed with 'base64:' (e.g. base64:<44-character-string>)."
        );
    }

    /**
     * Ensure the key is not an obvious placeholder or all zeroes.
     *
     * @throws RuntimeException
     */
    private static function assertNotWeakKey(string $binaryKey): void
    {
        // Rejects all zeros or repetitive trivial keys
        if ($binaryKey === str_repeat("\0", 32) || $binaryKey === str_repeat("\xFF", 32)) {
            throw new RuntimeException("NEXUS SECURITY REJECTION: APP_KEY is a trivial key. Regenerate a cryptographically secure key immediately.");
        }
    }

    /**
     * Get path of the loaded environment file.
     */
    public static function getLoadedPath(): ?string
    {
        return self::$loadedPath;
    }

    /**
     * Check if environment is running in production.
     */
    public static function isProduction(): bool
    {
        return strtolower(self::string('APP_ENV', 'production')) === 'production';
    }

    /**
     * Check if running in a local development environment.
     */
    public static function isLocal(): bool
    {
        $env = strtolower(self::string('APP_ENV', 'production'));
        return in_array($env, ['local', 'development', 'dev', 'testing', 'test'], true);
    }

    /**
     * Check if debug mode is active.
     */
    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }
}
