<?php

declare(strict_types=1);

/**
 * Nexus Shield v2 - Global Router & Server Action Helper Functions
 */

if (!function_exists('server_action')) {
    /**
     * Register or retrieve a Server Action.
     *
     * Example:
     * server_action('save', function(array $input) {
     *     return ['success' => true, 'toast' => ['type' => 'success', 'message' => 'Profile updated!']];
     * });
     *
     * @param string $name
     * @param callable|null $handler
     * @return mixed
     */
    function server_action(string $name, ?callable $handler = null): mixed
    {
        if ($handler !== null) {
            \Core\Router\ServerAction::register($name, $handler);
            return null;
        }
        return \Core\Router\ServerAction::get($name);
    }
}
