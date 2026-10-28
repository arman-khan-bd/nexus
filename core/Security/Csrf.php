<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Http\Request;
use Core\Http\Response;

/**
 * Class Csrf
 *
 * Enterprise Cross-Site Request Forgery (CSRF) Shield for Nexus.
 *
 * Features:
 * - 256-bit cryptographically secure session-bound tokens
 * - Timing-safe verification using hash_equals()
 * - Automatic inspection of mutating HTTP methods (POST, PUT, DELETE, PATCH)
 * - Support for HTML hidden fields, meta tags, and AJAX request headers
 * - Webhook exemption capabilities
 */
final class Csrf
{
    private const SESSION_KEY = '_nexus_csrf_token';
    private const HEADER_NAME = 'X-CSRF-TOKEN';

    /**
     * Exempt URL routes (e.g. external webhooks).
     *
     * @var array<string>
     */
    private static array $exemptRoutes = [];

    /**
     * Get or initialize the active CSRF token bound to the current session.
     */
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Force regenerate a fresh CSRF token in session.
     */
    public static function regenerateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $newToken = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $newToken;
        return $newToken;
    }

    /**
     * Generate HTML hidden input field for forms.
     */
    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_token" value="' . $token . '">';
    }

    /**
     * Generate HTML meta tag for client-side JavaScript / Alpine.js integration.
     */
    public static function meta(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<meta name="csrf-token" content="' . $token . '">';
    }

    /**
     * Return canonical CSRF header name.
     */
    public static function headerName(): string
    {
        return self::HEADER_NAME;
    }

    /**
     * Register a route path as exempt from CSRF verification.
     */
    public static function exempt(string $path): void
    {
        self::$exemptRoutes[] = '/' . trim($path, '/');
    }

    /**
     * Verify submitted CSRF token using constant-time hash_equals().
     */
    public static function verify(Request $request): bool
    {
        $sessionToken = self::token();
        if ($sessionToken === '') {
            return false;
        }

        // 1. Check HTTP Headers (X-CSRF-TOKEN or X-XSRF-TOKEN)
        $headerToken = $request->getHeader('x-csrf-token') ?? $request->getHeader('x-xsrf-token');
        if (is_string($headerToken) && hash_equals($sessionToken, $headerToken)) {
            return true;
        }

        // 2. Check Form POST Data (_token)
        $postToken = $request->getPost('_token');
        if (is_string($postToken) && hash_equals($sessionToken, $postToken)) {
            return true;
        }

        // 3. Check JSON Payload (_token)
        $json = $request->getJson();
        if (isset($json['_token']) && is_string($json['_token']) && hash_equals($sessionToken, $json['_token'])) {
            return true;
        }

        return false;
    }

    /**
     * Inspect request method and validate CSRF on mutating requests (POST, PUT, DELETE, PATCH).
     * Returns a 403 Response if verification fails, or null if allowed.
     */
    public static function validateRequest(Request $request): ?Response
    {
        $method = $request->getMethod();

        // Safe idempotent methods require no CSRF validation
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        // Check if route is explicitly exempt
        $path = '/' . trim($request->getPath(), '/');
        foreach (self::$exemptRoutes as $exempt) {
            if ($path === $exempt || str_starts_with($path, rtrim($exempt, '/') . '/')) {
                return null;
            }
        }

        // Mutating request: Verify CSRF token
        if (!self::verify($request)) {
            self::logCsrfViolation($request);

            if ($request->isAjax() || str_contains($request->getHeader('accept', ''), 'application/json')) {
                return Response::json([
                    'error'   => 'CSRF Token Mismatch',
                    'message' => 'Your security token is missing or has expired. Please refresh the page and try again.',
                    'status'  => 403,
                ], 403);
            }

            return self::createErrorResponse();
        }

        return null;
    }

    /**
     * Log CSRF violation incident to storage/logs/security.log.
     */
    private static function logCsrfViolation(Request $request): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/security.log';
        $logDir = dirname($logPath);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }

        $entry = sprintf(
            "[%s] [CSRF_VIOLATION] IP: %s | Method: %s | URI: %s | Action: %s | UA: %s\n",
            date('Y-m-d H:i:s'),
            $request->getIp(),
            $request->getMethod(),
            $request->getPath(),
            $request->getActionName() ?? 'none',
            $request->getUserAgent()
        );

        @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Create 403 Forbidden response for CSRF mismatch.
     */
    private static function createErrorResponse(): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - CSRF Token Expired | Nexus</title>
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
        .card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
            padding: 2.75rem 2rem;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }
        .badge {
            display: inline-block;
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
            padding: 0.25rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1.5rem;
        }
        h1 { font-size: 1.75rem; font-weight: 700; color: #ffffff; margin-bottom: 0.75rem; }
        p { color: #9ca3af; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.75rem; }
        .btn {
            display: inline-block;
            background: #3b82f6;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">Security Token Mismatch</div>
        <h1>403 Invalid CSRF Token</h1>
        <p>The form or server action you submitted was missing a valid cryptographic CSRF token, or your session has timed out.</p>
        <a href="javascript:location.reload()" class="btn">Refresh Page &amp; Try Again</a>
    </div>
</body>
</html>
HTML;

        return Response::html($html, 403);
    }
}
