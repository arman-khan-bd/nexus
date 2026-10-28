<?php

declare(strict_types=1);

/**
 * Nexus Shield v2 - Front Controller
 *
 * Next.js-inspired, JSX-compiled, ultra-secure PHP micro-framework.
 * Architected specifically for cPanel shared hosting (public_html root compatible, zero Node.js).
 */

// 0. PHP Built-in CLI Server Static Asset Handler (php nexus serve)
if (php_sapi_name() === 'cli-server') {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($uriPath !== null && $uriPath !== '/') {
        // Disallow direct access to sensitive paths in dev server
        if (preg_match('#^/(\.env|\.git|core|storage|packages|vendor|database|tests)#i', $uriPath)) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }
        $realFile = __DIR__ . $uriPath;
        if (file_exists($realFile) && !is_dir($realFile)) {
            return false; // Serve asset as-is
        }
    }
}

$baseDir = __DIR__;

// 1. PSR-4 Autoloading: Composer primary with native zero-dependency fallback
if (file_exists($baseDir . '/vendor/autoload.php')) {
    require_once $baseDir . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class) use ($baseDir) {
        $prefixes = [
            'Core\\'               => $baseDir . '/core/',
            'App\\'                => $baseDir . '/app/',
            'Components\\'         => $baseDir . '/components/',
            'Nexus\\Installer\\'   => $baseDir . '/packages/cpanel-installer/src/',
            'NextPHP\\Installer\\' => $baseDir . '/packages/cpanel-installer/src/',
        ];

        foreach ($prefixes as $prefix => $directory) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) === 0) {
                $relativeClass = substr($class, $len);
                $file = $directory . str_replace('\\', '/', $relativeClass) . '.php';

                if (file_exists($file)) {
                    require_once $file;
                    return;
                }
            }
        }
    });
}

// 2. Installer Bridge: Intercept uninstalled applications before Env/Kernel boot
\Core\Router\InstallerBridge::handle($baseDir);

// 3. Load and lock immutable environment variables
\Core\Env::load($baseDir . '/.env');

// 4. Boot the Application Security Kernel, WAF, SessionGuard, and App Router
$kernel = \Core\Kernel::boot($baseDir);
$kernel->handle();
