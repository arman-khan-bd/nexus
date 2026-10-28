<?php

declare(strict_types=1);

namespace Core\Router;

use Core\Http\Request;
use Core\Http\Response;
use Core\Security\Csrf;
use Core\Env;
use Throwable;

/**
 * Class ServerAction
 *
 * Next.js-inspired Server Actions engine for Nexus.
 * Enables direct server-side execution from UI forms, buttons, and Alpine.js
 * with zero manual AJAX endpoint coding.
 *
 * Features:
 * - Automatic CSRF validation via Csrf & SessionGuard
 * - Input parameter sanitization and reserved field stripping
 * - Support for global registry and page-level declared actions
 * - Unified UI state responses (JSON, Toast notifications, DOM morphing, redirects)
 * - Hardened exception containment with environment-aware debug output
 */
final class ServerAction
{
    /**
     * Globally registered server actions.
     *
     * @var array<string, callable>
     */
    private static array $actions = [];

    /**
     * Register a server action globally.
     */
    public static function register(string $name, callable $handler): void
    {
        self::$actions[$name] = $handler;
    }

    /**
     * Retrieve a registered server action handler.
     */
    public static function get(string $name): ?callable
    {
        return self::$actions[$name] ?? null;
    }

    /**
     * Check if an action is registered.
     */
    public static function has(string $name): bool
    {
        return isset(self::$actions[$name]);
    }

    /**
     * Get all registered action names.
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return array_keys(self::$actions);
    }

    /**
     * Clear all registered actions (useful during testing).
     */
    public static function clear(): void
    {
        self::$actions = [];
    }

    /**
     * Determine if the incoming request is targeting a Server Action.
     */
    public static function isAction(Request $request): bool
    {
        return $request->isServerAction() ||
               $request->hasHeader('x-nextphp-action') ||
               $request->hasHeader('x-nexus-action') ||
               isset($_POST['_action']) ||
               isset($_POST['action']) ||
               (isset($request->getJson()['_action'])) ||
               (isset($request->getJson()['action']));
    }

    /**
     * Extract the action name from headers, POST data, or JSON body.
     */
    public static function getActionName(Request $request): ?string
    {
        // 1. Header: X-NextPHP-Action or X-Nexus-Action
        $header = $request->getHeader('x-nextphp-action') ?? $request->getHeader('x-nexus-action');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        // 2. Request getActionName() helper
        $reqAction = $request->getActionName();
        if (is_string($reqAction) && trim($reqAction) !== '') {
            return trim($reqAction);
        }

        // 3. POST data
        $postAction = $request->getPost('_action') ?? $request->getPost('action');
        if (is_string($postAction) && trim($postAction) !== '') {
            return trim($postAction);
        }

        // 4. JSON body
        $json = $request->getJson();
        if (isset($json['_action']) && is_string($json['_action']) && trim($json['_action']) !== '') {
            return trim($json['_action']);
        }
        if (isset($json['action']) && is_string($json['action']) && trim($json['action']) !== '') {
            return trim($json['action']);
        }

        return null;
    }

    /**
     * Execute a server action with full security defenses.
     *
     * @param Request $request
     * @param string|null $pageFile Optional path to page.php to discover page-scoped actions
     * @return Response|null
     */
    public static function handle(Request $request, ?string $pageFile = null): ?Response
    {
        if (!self::isAction($request)) {
            return null;
        }

        $actionName = self::getActionName($request);
        if ($actionName === null || $actionName === '') {
            return Response::json([
                'success' => false,
                'error'   => 'Missing Action Identifier',
                'message' => 'No server action identifier was specified in the request.'
            ], 400);
        }

        // 1. Verify CSRF for mutating requests
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            if (!Csrf::verify($request)) {
                return Response::json([
                    'success' => false,
                    'error'   => 'CSRF Token Mismatch',
                    'message' => 'Security token invalid or expired. Please refresh the page.'
                ], 403);
            }
        }

        // 2. Sanitize and prepare clean input payload
        $rawInput = $request->getInput();
        $input = self::sanitizeInput($rawInput);

        // 3. Resolve handler
        $handler = self::resolveHandler($actionName, $pageFile, $request, $input);
        if ($handler === null) {
            return Response::json([
                'success' => false,
                'error'   => 'Action Not Found',
                'message' => "Server action [{$actionName}] is not registered."
            ], 404);
        }

        // 4. Execute handler with exception containment
        try {
            $result = $handler($input, $request);
            return self::formatResult($result);
        } catch (Throwable $e) {
            self::logError($e, $actionName, $request);

            return Response::json([
                'success'   => false,
                'error'     => 'Action Execution Failed',
                'message'   => Env::isDebug() ? $e->getMessage() : 'An error occurred while executing the server action.',
                'exception' => Env::isDebug() ? get_class($e) : null,
                'file'      => Env::isDebug() ? $e->getFile() : null,
                'line'      => Env::isDebug() ? $e->getLine() : null,
            ], 500);
        }
    }

    /**
     * Resolve the action callable from registry, page-file returns, or page-file functions.
     */
    private static function resolveHandler(string $actionName, ?string $pageFile, Request $request, array $input): ?callable
    {
        // A. Globally registered action
        if (self::has($actionName)) {
            return self::get($actionName);
        }

        // B. Page-scoped action declared in pageFile
        if ($pageFile !== null && file_exists($pageFile)) {
            $closure = function () use ($pageFile, $request, $input, $actionName): ?callable {
                $result = require $pageFile;

                // Check if page file returned an actions map
                if (is_array($result) && isset($result['actions'][$actionName]) && is_callable($result['actions'][$actionName])) {
                    return $result['actions'][$actionName];
                }

                // Check if page file called server_action() during inclusion
                if (ServerAction::has($actionName)) {
                    return ServerAction::get($actionName);
                }

                // Check if a global function named action_{name} was defined
                $functionName = 'action_' . strtolower(str_replace(['-', '.'], '_', $actionName));
                if (function_exists($functionName)) {
                    return $functionName;
                }

                return null;
            };

            $handler = $closure();
            if ($handler !== null) {
                return $handler;
            }
        }

        // C. Check global function action_{name}
        $functionName = 'action_' . strtolower(str_replace(['-', '.'], '_', $actionName));
        if (function_exists($functionName)) {
            return $functionName;
        }

        return null;
    }

    /**
     * Format action result into an HTTP Response with standardized metadata.
     */
    public static function formatResult(mixed $result): Response
    {
        // 1. Direct Response object
        if ($result instanceof Response) {
            return $result;
        }

        // 2. Structured array
        if (is_array($result)) {
            if (!isset($result['success'])) {
                $result['success'] = !isset($result['error']);
            }
            return Response::json($result);
        }

        // 3. String (HTML snippet for DOM morphing)
        if (is_string($result)) {
            return Response::morph($result, [
                'X-NextPHP-Morph' => 'true',
                'X-Nexus-Morph'   => 'true',
            ]);
        }

        // 4. Boolean or null
        if (is_bool($result) || is_null($result)) {
            return Response::json([
                'success' => $result ?? true,
            ]);
        }

        // 5. Generic object / scalar
        return Response::json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * Clean and sanitize input parameters, stripping reserved framework fields.
     */
    public static function sanitizeInput(array $input): array
    {
        $reserved = ['_token', '_action', 'action', '_method'];
        $clean = [];

        foreach ($input as $key => $value) {
            if (in_array($key, $reserved, true)) {
                continue;
            }
            $clean[$key] = self::sanitizeValue($value);
        }

        return $clean;
    }

    /**
     * Recursively strip null bytes and trim strings.
     */
    private static function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim(str_replace("\0", '', $value));
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $k => $v) {
                $sanitized[$k] = self::sanitizeValue($v);
            }
            return $sanitized;
        }

        return $value;
    }

    /**
     * Log Server Action exceptions.
     */
    private static function logError(Throwable $e, string $actionName, Request $request): void
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }

        $logEntry = sprintf(
            "[%s] [SERVER_ACTION_ERROR] Action: %s | IP: %s | URI: %s | %s: %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            $actionName,
            $request->getIp(),
            $request->getPath(),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        @file_put_contents($logDir . '/nexus_error.log', $logEntry, FILE_APPEND | LOCK_EX);
    }
}

// Load global server_action helper
require_once __DIR__ . '/helpers.php';

// Also maintain namespaced helper for Core\Router\server_action
if (!function_exists(__NAMESPACE__ . '\\server_action')) {
    function server_action(string $name, ?callable $handler = null): mixed
    {
        return \server_action($name, $handler);
    }
}
