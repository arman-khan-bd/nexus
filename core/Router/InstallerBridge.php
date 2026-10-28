<?php

declare(strict_types=1);

namespace Core\Router;

use NextPHP\Installer\Installer;

/**
 * Class InstallerBridge
 *
 * Micro-framework routing bridge between Nexus Shield v2 and nexus/cpanel-installer.
 *
 * Interception Pipeline:
 * 1. Checks for the presence of the installation lockfile (storage/app.installed or .installed)
 * 2. If uninstalled, intercepts the HTTP request before Env::load or Kernel boot
 * 3. Launches the graphical Shadcn UI installer wizard directly in the browser
 * 4. Bypasses standard application routes until database migrations and admin setup are complete
 */
final class InstallerBridge
{
    /**
     * Intercept uninstalled applications and route to the installer wizard.
     *
     * @param string $baseDir Host application root directory
     * @return bool True if application is already installed, false if installer ran
     */
    public static function handle(string $baseDir): bool
    {
        if (self::isInstalled($baseDir)) {
            return true;
        }

        // If nextphp/cpanel-installer is available, run installer wizard
        if (class_exists(Installer::class)) {
            Installer::run($baseDir);
            exit(0);
        }

        // Fallback if installer package is missing on unconfigured application
        self::renderInstallerMissing($baseDir);
        exit(1);
    }

    /**
     * Check if application has completed installation.
     */
    public static function isInstalled(string $baseDir): bool
    {
        // 1. Primary lockfile: storage/app.installed
        if (file_exists($baseDir . '/storage/app.installed')) {
            return true;
        }

        // 2. Secondary root lockfiles: .installed or app.installed
        if (file_exists($baseDir . '/.installed') || file_exists($baseDir . '/app.installed')) {
            if (!file_exists($baseDir . '/storage/app.installed')) {
                @file_put_contents($baseDir . '/storage/app.installed', json_encode([
                    'installed_at' => date('c'),
                    'source'       => 'root-lockfile-sync',
                ]));
            }
            return true;
        }

        // 3. Environment configuration: If .env exists with APP_KEY and DB_DATABASE configured
        $envFile = $baseDir . '/.env';
        if (file_exists($envFile)) {
            $content = (string)@file_get_contents($envFile);
            if (preg_match('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=/m', $content) &&
                preg_match('/^DB_DATABASE=.+$/m', $content)) {
                // Application was already configured; write lockfiles so installer is safely locked
                @file_put_contents($baseDir . '/storage/app.installed', json_encode([
                    'installed_at' => date('c'),
                    'source'       => 'env-configured',
                ]));
                @file_put_contents($baseDir . '/.installed', json_encode([
                    'installed_at' => date('c'),
                    'source'       => 'env-configured',
                ]));
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback view if the installer package is not present and .env is missing.
     */
    private static function renderInstallerMissing(string $baseDir): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Unconfigured | Nexus</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #030712; color: #f3f4f6; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; display: flex; align-items: center; justify-content: center; height: 100vh; padding: 1.5rem; }
        .box { background: #111827; border: 1px solid #1f2937; border-left: 4px solid #3b82f6; border-radius: 12px; padding: 2rem; max-width: 600px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7); }
        h1 { font-size: 1.25rem; font-weight: 700; color: #fff; margin-bottom: 0.75rem; }
        p { color: #9ca3af; font-size: 0.9rem; line-height: 1.6; margin-bottom: 1.25rem; }
        code { background: #030712; border: 1px solid #1e293b; padding: 0.6rem 0.85rem; border-radius: 6px; display: block; color: #38bdf8; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Nexus Application Unconfigured</h1>
        <p>This application has not yet been initialized and the graphical cPanel installer package was not detected.</p>
        <p>To enable the zero-SSH web setup wizard, install the installer package:</p>
        <code>composer require nexus-lab/cpanel-installer</code>
    </div>
</body>
</html>
HTML;
    }
}
