<?php

declare(strict_types=1);

namespace Components\Ui;

use Core\UI\Component;
use Core\Security\Escaper;

/**
 * Class Badge
 *
 * Shadcn-inspired Badge with status variants and icons.
 */
class Badge extends Component
{
    private const VARIANTS = [
        'default'     => 'border-transparent bg-blue-600 text-white',
        'secondary'   => 'border-transparent bg-secondary text-secondary-foreground',
        'destructive' => 'border-transparent bg-red-600 text-white',
        'outline'     => 'border-border text-foreground',
        'success'     => 'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        'warning'     => 'border-amber-500/20 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    ];

    public function render(array $props, ?callable $slot = null): string
    {
        $variant = $props['variant'] ?? 'default';
        $icon = $props['icon'] ?? null;
        $pulse = (bool)($props['pulse'] ?? false);

        $baseClasses = 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none select-none';
        $variantClasses = self::VARIANTS[$variant] ?? self::VARIANTS['default'];

        $classes = $this->mergeClasses("{$baseClasses} {$variantClasses}", $props['class'] ?? '');
        $attrs = $this->renderAttributes($props, ['variant', 'icon', 'pulse', 'class']);

        $iconHtml = '';
        if ($pulse) {
            $iconHtml = '<span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>';
        } elseif ($icon !== null) {
            $iconHtml = "<i data-lucide=\"" . Escaper::attr($icon) . "\" class=\"w-3 h-3\"></i>";
        }

        $children = $this->renderSlot($slot);
        return "<span class=\"{$classes}\"{$attrs}>{$iconHtml}{$children}</span>";
    }
}
