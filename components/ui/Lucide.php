<?php

declare(strict_types=1);

namespace Components\Ui;

use Core\UI\Component;
use Core\Security\Escaper;

/**
 * Class Lucide
 *
 * Lucide Icon Component.
 */
class Lucide extends Component
{
    public function render(array $props, ?callable $slot = null): string
    {
        $name = $props['icon'] ?? $props['name'] ?? 'circle';
        $size = $props['size'] ?? null;

        $baseClasses = 'inline-block shrink-0';
        if ($size) {
            $baseClasses .= " w-{$size} h-{$size}";
        } else {
            $baseClasses .= ' w-4 h-4';
        }

        $classes = $this->mergeClasses($baseClasses, $props['class'] ?? '');
        $attrs = $this->renderAttributes($props, ['icon', 'name', 'size', 'class']);

        return "<i data-lucide=\"" . Escaper::attr($name) . "\" class=\"{$classes}\"{$attrs}></i>";
    }
}
