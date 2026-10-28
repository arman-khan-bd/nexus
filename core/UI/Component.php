<?php

declare(strict_types=1);

namespace Core\UI;

use Core\Security\Escaper;

/**
 * Class Component
 *
 * Base abstract class for all Nexus UI components.
 * Provides Shadcn class merging, Alpine.js attribute normalization,
 * and slot evaluation.
 */
abstract class Component
{
    /**
     * Render the component into HTML.
     *
     * @param array $props Component attributes and parameters.
     * @param callable|null $slot Child content / slot callback.
     * @return string
     */
    abstract public function render(array $props, ?callable $slot = null): string;

    /**
     * Safely evaluate and return the slot / child content.
     */
    protected function renderSlot(?callable $slot): string
    {
        if ($slot === null) {
            return '';
        }

        ob_start();
        try {
            $output = $slot();
            $buffered = ob_get_clean();
            return is_string($output) && $output !== '' ? $output : (string)$buffered;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Merge base Tailwind classes with user-provided override classes.
     */
    protected function mergeClasses(string $base, ?string $override): string
    {
        if (empty($override)) {
            return trim($base);
        }

        return trim("{$base} {$override}");
    }

    /**
     * Render remaining props as safe HTML/Alpine attributes.
     */
    protected function renderAttributes(array $props, array $exclude = []): string
    {
        $exclude = array_merge($exclude, ['slot', 'children']);
        $html = '';

        foreach ($props as $key => $value) {
            if (in_array($key, $exclude, true) || $value === null) {
                continue;
            }

            // Boolean attribute (e.g. disabled, required, autofocus)
            if (is_bool($value)) {
                if ($value) {
                    $html .= ' ' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                }
                continue;
            }

            // Alpine.js directives (@click, x-data, x-show, :class) or standard HTML
            $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $safeVal = Escaper::attr($value);

            $html .= " {$safeKey}=\"{$safeVal}\"";
        }

        return $html;
    }
}
