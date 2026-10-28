<?php

declare(strict_types=1);

namespace Core;

use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AntiDdos;
use Core\Security\Firewall;
use Core\Security\SessionGuard;
use Core\Security\Csrf;
use Core\Router\AppRouter;
use Throwable;
use ErrorException;

/**
 * Class Kernel
 *
 * Nexus Application Security Kernel.
 * Orchestrates error handling, defensive WAF shielding, rate limiting,
 * hardened session lifecycle, and Next.js-style file-system routing.
 */
final class Kernel
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Boot the Nexus application kernel.
     */
    public static function boot(string $basePath): self
    {
        $kernel = new self($basePath);
        $kernel->registerErrorHandlers();
        return $kernel;
    }

    /**
     * Handle incoming HTTP request and emit Response.
     */
    public function handle(): void
    {
        try {
            $request = Request::capture();

            // 1. Lightweight cPanel Anti-DDoS, Bot Mitigation & Sliding Window Shield
            $ddosBlock = AntiDdos::inspect($request);
            if ($ddosBlock instanceof Response) {
                $ddosBlock->send();
                return;
            }

            // 2. Deep Input Sanity & Application Firewall (OWASP Top 10)
            $firewallBlock = Firewall::inspect($request);
            if ($firewallBlock instanceof Response) {
                $firewallBlock->send();
                return;
            }

            // 3. Security Hardened Session & Subnet Anti-Hijacking
            SessionGuard::start($request);

            // 4. Session-Bound Cryptographic CSRF Defense (Mutating HTTP Verbs)
            $csrfBlock = Csrf::validateRequest($request);
            if ($csrfBlock instanceof Response) {
                $csrfBlock->send();
                return;
            }

            // 5. App Router (Next.js App Router convention & Alpine Morph SPA)
            $router = new AppRouter($this->basePath . '/pages');
            $response = $router->dispatch($request);

            // 6. Transmit Response
            $response->send();
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Register strict error & exception handlers.
     */
    private function registerErrorHandlers(): void
    {
        error_reporting(E_ALL);

        set_error_handler(function (int $severity, string $message, string $file, int $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e) {
            $this->handleException($e);
        });
    }

    /**
     * Exception presentation: Rich debugger in dev, hardened fortress in production.
     */
    private function handleException(Throwable $e): void
    {
        // Log exception to storage/logs
        $logDir = $this->basePath . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }
        $logEntry = sprintf(
            "[%s] [EXCEPTION] %s: %s in %s:%d\nStack Trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        @file_put_contents($logDir . '/nexus_error.log', $logEntry, FILE_APPEND | LOCK_EX);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        if (Env::isDebug()) {
            $this->renderDebugException($e);
        } else {
            $this->renderProductionError();
        }
    }

    /**
     * Developer debug error view with stack trace.
     */
    private function renderDebugException(Throwable $e): void
    {
        $class = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nexus Error: {$message}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #030712; color: #f3f4f6; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; padding: 2rem; }
        .wrapper { max-width: 1000px; margin: 0 auto; }
        .header { background: #111827; border: 1px solid #1f2937; border-left: 4px solid #ef4444; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .badge { display: inline-block; background: rgba(239, 68, 68, 0.2); color: #f87171; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; margin-bottom: 0.75rem; }
        h1 { font-size: 1.25rem; font-weight: 600; color: #f9fafb; margin-bottom: 0.5rem; word-break: break-all; }
        .loc { color: #9ca3af; font-size: 0.85rem; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 8px; padding: 1.5rem; }
        h2 { font-size: 0.95rem; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; margin-bottom: 1rem; }
        pre { background: #030712; border: 1px solid #1e293b; padding: 1rem; border-radius: 6px; overflow-x: auto; color: #cbd5e1; font-size: 0.85rem; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <span class="badge">{$class}</span>
            <h1>{$message}</h1>
            <div class="loc">In <strong>{$file}</strong> on line <strong>{$line}</strong></div>
        </div>
        <div class="card">
            <h2>Stack Trace</h2>
            <pre>{$trace}</pre>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Hardened production error page.
     */
    private function renderProductionError(): void
    {
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>500 - Internal Server Error</title>
    <style>
        body { background: #090d16; color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .box { border: 1px solid #1e293b; background: #0f172a; padding: 2.5rem; border-radius: 12px; text-align: center; max-width: 480px; }
        h1 { color: #ef4444; font-size: 1.75rem; margin: 0 0 0.75rem; }
        p { color: #94a3b8; font-size: 0.95rem; line-height: 1.5; margin: 0; }
    </style>
</head>
<body>
    <div class="box">
        <h1>500 Server Error</h1>
        <p>An unexpected error occurred while processing your request. The incident has been recorded.</p>
    </div>
</body>
</html>
HTML;
    }
}
