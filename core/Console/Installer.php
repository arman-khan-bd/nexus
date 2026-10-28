<?php

declare(strict_types=1);

namespace Core\Console;

/**
 * Class Installer
 *
 * Automates project bootstrap on 'composer create-project',
 * sets up strict storage isolation (0700), generates Libsodium 256-bit APP_KEY,
 * and initializes default configuration.
 */
final class Installer
{
    /**
     * Post create-project hook for Composer.
     */
    public static function postCreateProject(): void
    {
        $baseDir = dirname(__DIR__, 2);
        echo "\n\033[1;36m===> Initializing Nexus Shield v2 Foundation...\033[0m\n";

        // 1. Storage directory creation with 0700 permissions
        self::setupStorageDirectories($baseDir);

        // 2. Generate .env from .env.example
        $envCreated = self::setupEnvironmentFile($baseDir);

        // 3. Generate 256-bit Libsodium APP_KEY
        self::generateAndWriteAppKey($baseDir, $envCreated);

        // 4. Link Installer Package Bridge readiness
        echo "Linked cPanel Installer Bridge (\Core\Router\InstallerBridge)\n";

        echo "\033[1;32m[OK] Nexus Shield boilerplate initialized successfully! Ready for cPanel deployment.\033[0m\n\n";
    }

    /**
     * Post install hook.
     */
    public static function postInstall(): void
    {
        $baseDir = dirname(__DIR__, 2);
        self::setupStorageDirectories($baseDir);
    }

    /**
     * Standalone key generation command.
     */
    public static function generateKeyCommand(): void
    {
        $baseDir = dirname(__DIR__, 2);
        $key = self::generateAndWriteAppKey($baseDir, true);
        echo "\033[1;32m[OK] Application key set successfully: {$key}\033[0m\n";
    }

    /**
     * Creates storage subdirectories with 0700 permissions.
     */
    public static function setupStorageDirectories(string $baseDir): void
    {
        $directories = [
            $baseDir . '/storage',
            $baseDir . '/storage/cache',
            $baseDir . '/storage/cache/views',
            $baseDir . '/storage/sessions',
            $baseDir . '/storage/ratelimit',
            $baseDir . '/storage/logs',
            $baseDir . '/storage/uploads',
            $baseDir . '/app',
            $baseDir . '/pages',
            $baseDir . '/components',
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
                echo "Created directory: " . str_replace($baseDir, '', $dir) . "\n";
            }
            // Enforce 0700 on Unix systems
            if (DIRECTORY_SEPARATOR !== '\\') {
                @chmod($dir, 0700);
            }
        }

        // Place a .gitignore inside storage to keep git clean
        $storageGitignore = $baseDir . '/storage/.gitignore';
        if (!file_exists($storageGitignore)) {
            $content = "*\n!.gitignore\n!cache/.gitkeep\n!cache/views/.gitkeep\n!sessions/.gitkeep\n!ratelimit/.gitkeep\n!logs/.gitkeep\n";
            file_put_contents($storageGitignore, $content);
        }

        // Add .gitkeep files
        $gitkeeps = [
            $baseDir . '/storage/cache/views/.gitkeep',
            $baseDir . '/storage/sessions/.gitkeep',
            $baseDir . '/storage/ratelimit/.gitkeep',
            $baseDir . '/storage/logs/.gitkeep',
            $baseDir . '/storage/uploads/.gitkeep',
        ];

        foreach ($gitkeeps as $keep) {
            if (!file_exists($keep)) {
                @file_put_contents($keep, '');
            }
        }

        // Deploy uploads execution shield .htaccess
        \Core\Security\FileShield::ensureStorageFortress($baseDir . '/storage/uploads');
    }

    /**
     * Copy .env.example to .env if .env does not exist.
     */
    public static function setupEnvironmentFile(string $baseDir): bool
    {
        $envPath = $baseDir . '/.env';
        $examplePath = $baseDir . '/.env.example';

        if (!file_exists($envPath)) {
            if (file_exists($examplePath)) {
                copy($examplePath, $envPath);
                echo "Generated .env file from .env.example.\n";
                return true;
            } else {
                file_put_contents($envPath, "APP_NAME=\"Nexus App\"\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\n");
                return true;
            }
        }

        return false;
    }

    /**
     * Generates a 256-bit cryptographically secure key and updates .env.
     */
    public static function generateAndWriteAppKey(string $baseDir, bool $force = false): string
    {
        $envPath = $baseDir . '/.env';
        if (!file_exists($envPath)) {
            self::setupEnvironmentFile($baseDir);
        }

        $envContent = (string)file_get_contents($envPath);

        // Check if APP_KEY already exists and is non-empty
        if (!$force && preg_match('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=/m', $envContent)) {
            echo "APP_KEY already exists in .env. Skipping key generation.\n";
            return '';
        }

        // Generate 256-bit (32 bytes) cryptographically secure random bytes
        $randomBytes = random_bytes(32);
        $encodedKey = 'base64:' . base64_encode($randomBytes);

        if (preg_match('/^APP_KEY=.*$/m', $envContent)) {
            $updated = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $encodedKey, $envContent);
        } else {
            $updated = $envContent . "\nAPP_KEY=" . $encodedKey . "\n";
        }

        file_put_contents($envPath, $updated);

        // Lock down .env permissions on Unix systems
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($envPath, 0600);
        }

        echo "Generated 256-bit cryptographic APP_KEY: {$encodedKey}\n";
        return $encodedKey;
    }
}
