<?php

declare(strict_types=1);

namespace Components\Ui;

use Core\UI\Component;
use Core\Security\Escaper;

/**
 * Class Card
 *
 * Shadcn-inspired Card Container.
 * Features adaptive dark/light card styling, title, description, footer, and slots.
 */
class Card extends Component
{
    public function render(array $props, ?callable $slot = null): string
    {
        $title = $props['title'] ?? null;
        $description = $props['description'] ?? null;
        $footer = $props['footer'] ?? null;
        $icon = $props['icon'] ?? null;

        $baseClasses = 'rounded-xl border border-border bg-card p-6 text-card-foreground shadow-sm transition-all hover:border-border/80';
        $classes = $this->mergeClasses($baseClasses, $props['class'] ?? '');
        $attrs = $this->renderAttributes($props, ['title', 'description', 'footer', 'icon', 'class']);

        $headerHtml = '';
        if ($title !== null || $description !== null) {
            $iconHtml = '';
            if ($icon !== null) {
                $iconHtml = "<div class=\"w-9 h-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0 mb-3\"><i data-lucide=\"" . Escaper::attr($icon) . "\" class=\"w-5 h-5\"></i></div>";
            }

            $titleHtml = $title !== null ? "<h3 class=\"text-lg font-semibold leading-none tracking-tight text-foreground\">" . Escaper::html($title) . "</h3>" : '';
            $descHtml = $description !== null ? "<p class=\"text-sm text-muted-foreground leading-relaxed mt-1.5\">" . Escaper::html($description) . "</p>" : '';
            $headerHtml = "<div class=\"flex flex-col mb-4\">{$iconHtml}{$titleHtml}{$descHtml}</div>";
        }

        $bodyHtml = $this->renderSlot($slot);

        $footerHtml = '';
        if ($footer !== null) {
            $footerHtml = "<div class=\"flex items-center pt-4 border-t border-border mt-6 text-sm text-muted-foreground\">" . Escaper::html($footer) . "</div>";
        }

        return "<div class=\"{$classes}\"{$attrs}>{$headerHtml}<div class=\"space-y-4\">{$bodyHtml}</div>{$footerHtml}</div>";
    }
}
