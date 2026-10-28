<?php

declare(strict_types=1);

namespace Components\Ui;

use Core\UI\Component;
use Core\Security\Escaper;

/**
 * Class Link
 *
 * Next.js-style SPA Navigation Link with URL XSS neutralization.
 * Embeds data-nexus-link for seamless pushState DOM morphing.
 */
class Link extends Component
{
    public function render(array $props, ?callable $slot = null): string
    {
        $href = Escaper::url($props['href'] ?? '#');
        $external = (bool)($props['external'] ?? false);
        $icon = $props['icon'] ?? null;

        $baseClasses = 'inline-flex items-center gap-1.5 font-medium transition-colors hover:text-foreground cursor-pointer';
        $classes = $this->mergeClasses($baseClasses, $props['class'] ?? 'text-primary hover:underline');

        $attrs = $this->renderAttributes($props, ['href', 'external', 'icon', 'class']);

        $spaAttr = !$external ? ' data-nexus-link' : ' target="_blank" rel="noopener noreferrer"';

        $iconHtml = '';
        if ($icon) {
            $iconHtml = "<i data-lucide=\"" . Escaper::attr($icon) . "\" class=\"w-4 h-4\"></i>";
        }

        $children = $this->renderSlot($slot);
        return "<a href=\"{$href}\" class=\"{$classes}\"{$spaAttr}{$attrs}>{$iconHtml}{$children}</a>";
    }
}
