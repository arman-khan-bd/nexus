<?php

declare(strict_types=1);

namespace Core\UI;

use RuntimeException;
use InvalidArgumentException;

/**
 * Class ComponentRegistry
 *
 * Discovers, registers, and renders Nexus UI Components.
 * Supports auto-discovery from components/ui/ and explicit component bindings.
 */
final class ComponentRegistry
{
    /**
     * Component name to class name registry map.
     *
     * @var array<string, class-string<Component>>
     */
    private static array $components = [];

    /**
     * Register a component class explicitly.
     */
    public static function register(string $name, string $componentClass): void
    {
        self::$components[strtolower($name)] = $componentClass;
    }

    /**
     * Render a registered or auto-discovered component.
     */
    public static function render(string $name, array $props = [], ?callable $slot = null): string
    {
        $component = self::resolve($name);
        return $component->render($props, $slot);
    }

    /**
     * Resolve component instance by name.
     */
    public static function resolve(string $name): Component
    {
        $key = strtolower($name);

        if (isset(self::$components[$key])) {
            $class = self::$components[$key];
            return new $class();
        }

        // Auto-discover in components/ui/ and components/
        $candidates = [
            "Components\\Ui\\{$name}",
            "Components\\{$name}",
            "App\\Components\\Ui\\{$name}",
            "App\\Components\\{$name}",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                self::$components[$key] = $candidate;
                return new $candidate();
            }
        }

        // Try direct file loading from base directory
        $baseDir = dirname(__DIR__, 2);
        $fileCandidates = [
            $baseDir . "/components/ui/{$name}.php" => "Components\\Ui\\{$name}",
            $baseDir . "/components/{$name}.php"    => "Components\\{$name}",
        ];

        foreach ($fileCandidates as $file => $class) {
            if (file_exists($file)) {
                require_once $file;
                if (class_exists($class)) {
                    self::$components[$key] = $class;
                    return new $class();
                }
            }
        }

        throw new RuntimeException("NEXUS COMPONENT NOT FOUND: [{$name}]. Ensure components/ui/{$name}.php exists.");
    }
}
