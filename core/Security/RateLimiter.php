<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Env;
use RuntimeException;

/**
 * Class RateLimiter
 *
 * High-performance, atomic file-backed sliding window rate limiter
 * engineered specifically for cPanel shared hosting environments.
 * Uses flock() exclusive locks to eliminate race conditions under concurrent requests.
 */
final class RateLimiter
{
    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? (dirname(__DIR__, 2) . '/storage/ratelimit');
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0700, true);
        }
    }

    /**
     * Singleton / default instance helper.
     */
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Determine if a given key has exceeded its maximum attempts within the sliding window.
     */
    public static function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds = 60): bool
    {
        return self::instance()->attempts($key, $decaySeconds) >= $maxAttempts;
    }

    /**
     * Record a hit against the sliding window for the given key and return the new count.
     */
    public static function hit(string $key, int $decaySeconds = 60): int
    {
        return self::instance()->recordHit($key, $decaySeconds);
    }

    /**
     * Get the number of recorded attempts for the given key in the active window.
     */
    public static function attempts(string $key, int $decaySeconds = 60): int
    {
        return self::instance()->getActiveAttempts($key, $decaySeconds);
    }

    /**
     * Get the remaining number of attempts available for the key.
     */
    public static function remaining(string $key, int $maxAttempts, int $decaySeconds = 60): int
    {
        $attempts = self::attempts($key, $decaySeconds);
        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Get the number of seconds until the key is released from throttling.
     */
    public static function availableIn(string $key, int $decaySeconds = 60): int
    {
        return self::instance()->getSecondsUntilAvailable($key, $decaySeconds);
    }

    /**
     * Reset the attempts for a given key.
     */
    public static function clear(string $key): void
    {
        self::instance()->purgeKey($key);
    }

    /**
     * Execute a callback if rate limit is not exceeded, otherwise throw or return null.
     */
    public static function attempt(string $key, int $maxAttempts, callable $callback, int $decaySeconds = 60): mixed
    {
        if (self::tooManyAttempts($key, $maxAttempts, $decaySeconds)) {
            return false;
        }

        self::hit($key, $decaySeconds);
        return $callback();
    }

    /**
     * Internal implementation: Atomic write of a new timestamp into the sliding window.
     */
    public function recordHit(string $key, int $decaySeconds): int
    {
        $filePath = $this->getFilePath($key);
        $fp = fopen($filePath, 'c+');
        if ($fp === false) {
            return 1;
        }

        $now = microtime(true);
        $threshold = $now - $decaySeconds;
        $count = 0;

        try {
            if (flock($fp, LOCK_EX)) {
                $raw = stream_get_contents($fp);
                $timestamps = [];

                if ($raw !== false && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        // Purge expired hits outside the sliding window
                        foreach ($decoded as $ts) {
                            if (is_numeric($ts) && (float)$ts > $threshold) {
                                $timestamps[] = (float)$ts;
                            }
                        }
                    }
                }

                $timestamps[] = $now;
                $count = count($timestamps);

                // Rewind and write updated sliding window array
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, (string)json_encode($timestamps));
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        return $count;
    }

    /**
     * Internal implementation: Read active hits in the sliding window without recording a new hit.
     */
    public function getActiveAttempts(string $key, int $decaySeconds): int
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return 0;
        }

        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            return 0;
        }

        $now = microtime(true);
        $threshold = $now - $decaySeconds;
        $count = 0;

        try {
            if (flock($fp, LOCK_SH)) {
                $raw = stream_get_contents($fp);
                if ($raw !== false && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $ts) {
                            if (is_numeric($ts) && (float)$ts > $threshold) {
                                $count++;
                            }
                        }
                    }
                }
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        return $count;
    }

    /**
     * Compute seconds until the oldest hit in the sliding window expires.
     */
    public function getSecondsUntilAvailable(string $key, int $decaySeconds): int
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return 0;
        }

        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            return 0;
        }

        $now = microtime(true);
        $threshold = $now - $decaySeconds;
        $oldestValid = null;

        try {
            if (flock($fp, LOCK_SH)) {
                $raw = stream_get_contents($fp);
                if ($raw !== false && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $ts) {
                            if (is_numeric($ts) && (float)$ts > $threshold) {
                                if ($oldestValid === null || (float)$ts < $oldestValid) {
                                    $oldestValid = (float)$ts;
                                }
                            }
                        }
                    }
                }
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        if ($oldestValid === null) {
            return 0;
        }

        $remaining = (int)ceil(($oldestValid + $decaySeconds) - $now);
        return max(1, $remaining);
    }

    /**
     * Purge rate limiter record for a key.
     */
    public function purgeKey(string $key): void
    {
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Generate file path using safe SHA-256 hash.
     */
    private function getFilePath(string $key): string
    {
        $hash = hash('sha256', $key);
        return $this->storagePath . '/rl_' . substr($hash, 0, 32) . '.json';
    }
}
