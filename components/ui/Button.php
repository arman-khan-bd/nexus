<?php

declare(strict_types=1);

namespace Components\Ui;

use Core\UI\Component;
use Core\Security\Escaper;

/**
 * Class Button
 *
 * Shadcn-inspired Button Component.
 * Supports variants (default, destructive, outline, secondary, ghost, link),
 * sizes, Lucide icons, loading spinners, and anchor 'as="a"' transformation.
 */
class Button extends Component
{
    private const VARIANTS = [
        'default'     => 'bg-blue-600 text-white hover:bg-blue-500 shadow-sm active:scale-[0.98]',
        'destructive' => 'bg-red-600 text-white hover:bg-red-500 shadow-sm active:scale-[0.98]',
        'outline'     => 'border border-input bg-background text-foreground hover:bg-accent hover:text-accent-foreground active:scale-[0.98]',
        'secondary'   => 'bg-secondary text-secondary-foreground hover:bg-secondary/80 shadow-sm active:scale-[0.98]',
        'ghost'       => 'text-foreground hover:bg-accent hover:text-accent-foreground',
        'link'        => 'text-primary underline-offset-4 hover:underline p-0 h-auto',
    ];

    private const SIZES = [
        'default' => 'h-10 px-4 py-2 text-sm',
        'sm'      => 'h-8 rounded-md px-3 text-xs',
        'lg'      => 'h-11 rounded-md px-8 text-base font-semibold',
        'icon'    => 'h-10 w-10 p-0',
    ];

    public function render(array $props, ?callable $slot = null): string
    {
        $as = strtolower($props['as'] ?? 'button');
        $variant = $props['variant'] ?? 'default';
        $size = $props['size'] ?? 'default';
        $icon = $props['icon'] ?? null;
        $iconRight = $props['iconRight'] ?? null;
        $loading = (bool)($props['loading'] ?? false);
        $disabled = (bool)($props['disabled'] ?? false) || $loading;

        $baseClasses = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:pointer-events-none disabled:opacity-50 cursor-pointer select-none';
        $variantClasses = self::VARIANTS[$variant] ?? self::VARIANTS['default'];
        $sizeClasses = self::SIZES[$size] ?? self::SIZES['default'];

        $classes = $this->mergeClasses("{$baseClasses} {$variantClasses} {$sizeClasses}", $props['class'] ?? '');
        $children = $this->renderSlot($slot);

        // Icon elements
        $leftIconHtml = '';
        if ($loading) {
            $leftIconHtml = '<svg class="animate-spin -ml-0.5 h-4 w-4 text-current" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>';
        } elseif ($icon) {
            $leftIconHtml = "<i data-lucide=\"" . Escaper::attr($icon) . "\" class=\"w-4 h-4\"></i>";
        }

        $rightIconHtml = '';
        if ($iconRight && !$loading) {
            $rightIconHtml = "<i data-lucide=\"" . Escaper::attr($iconRight) . "\" class=\"w-4 h-4\"></i>";
        }

        $content = "{$leftIconHtml}{$children}{$rightIconHtml}";

        $attrs = $this->renderAttributes($props, ['variant', 'size', 'icon', 'iconRight', 'loading', 'class', 'as']);

        if ($as === 'a') {
            $href = Escaper::url($props['href'] ?? '#');
            return "<a href=\"{$href}\" class=\"{$classes}\"{$attrs}>{$content}</a>";
        }

        $disabledAttr = $disabled ? ' disabled' : '';
        $type = Escaper::attr($props['type'] ?? 'button');

        return "<button type=\"{$type}\" class=\"{$classes}\"{$disabledAttr}{$attrs}>{$content}</button>";
    }
}
