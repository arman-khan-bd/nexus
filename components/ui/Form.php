<?php

declare(strict_types=1);

namespace Components\Ui;

use Core\UI\Component;
use Core\Security\Csrf;
use Core\Security\Escaper;

/**
 * Class Form
 *
 * Secure Form Component with automatic CSRF token injection
 * and HTTP method spoofing (PUT, DELETE, PATCH).
 */
class Form extends Component
{
    public function render(array $props, ?callable $slot = null): string
    {
        $rawMethod = strtoupper($props['method'] ?? 'POST');
        $action = Escaper::url($props['action'] ?? '');
        $nexusAction = $props['nexusAction'] ?? $props['actionName'] ?? null;

        $formMethod = in_array($rawMethod, ['GET', 'POST'], true) ? $rawMethod : 'POST';

        $methodSpoofHtml = '';
        if (in_array($rawMethod, ['PUT', 'DELETE', 'PATCH'], true)) {
            $methodSpoofHtml = '<input type="hidden" name="_method" value="' . Escaper::attr($rawMethod) . '">';
        }

        $csrfHtml = '';
        if ($formMethod !== 'GET') {
            $csrfHtml = Csrf::field();
        }

        $serverActionHtml = '';
        if ($nexusAction !== null) {
            $serverActionHtml = '<input type="hidden" name="_action" value="' . Escaper::attr((string)$nexusAction) . '">';
        }

        $classes = $this->mergeClasses('space-y-4', $props['class'] ?? '');
        $attrs = $this->renderAttributes($props, ['method', 'action', 'nexusAction', 'actionName', 'class']);

        $children = $this->renderSlot($slot);

        return "<form action=\"{$action}\" method=\"{$formMethod}\" class=\"{$classes}\"{$attrs}>{$csrfHtml}{$methodSpoofHtml}{$serverActionHtml}{$children}</form>";
    }
}
