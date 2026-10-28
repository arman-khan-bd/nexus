<?php

declare(strict_types=1);

namespace Components\Ui;

use Core\UI\Component;
use Core\Security\Escaper;

/**
 * Class Input
 *
 * Shadcn-inspired Text Input with label, validation error feedback,
 * and optional leading icon. Supports Light and Dark modes.
 */
class Input extends Component
{
    public function render(array $props, ?callable $slot = null): string
    {
        $label = $props['label'] ?? null;
        $error = $props['error'] ?? null;
        $icon = $props['icon'] ?? null;
        $name = $props['name'] ?? '';
        $id = $props['id'] ?? ($name !== '' ? $name : 'input_' . bin2hex(random_bytes(4)));
        $type = $props['type'] ?? 'text';
        $placeholder = $props['placeholder'] ?? '';
        $value = $props['value'] ?? '';

        $baseInput = 'flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 transition-colors';
        if ($error) {
            $baseInput .= ' border-destructive focus-visible:ring-destructive';
        }
        if ($icon) {
            $baseInput .= ' pl-9';
        }

        $inputClasses = $this->mergeClasses($baseInput, $props['class'] ?? '');
        $attrs = $this->renderAttributes($props, ['label', 'error', 'icon', 'class', 'placeholder', 'type', 'name', 'id', 'value']);

        $labelHtml = '';
        if ($label !== null) {
            $labelHtml = "<label for=\"{$id}\" class=\"text-xs font-semibold text-foreground block mb-1.5 select-none\">" . Escaper::html($label) . "</label>";
        }

        $iconHtml = '';
        if ($icon !== null) {
            $iconHtml = "<div class=\"absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none\"><i data-lucide=\"" . Escaper::attr($icon) . "\" class=\"w-4 h-4\"></i></div>";
        }

        $errorHtml = '';
        if ($error !== null && $error !== '') {
            $errorHtml = "<p class=\"text-xs text-destructive font-medium mt-1\">" . Escaper::html($error) . "</p>";
        }

        $placeholderAttr = $placeholder !== '' ? " placeholder=\"" . Escaper::attr($placeholder) . "\"" : '';
        $valueAttr = $value !== '' ? " value=\"" . Escaper::attr($value) . "\"" : '';
        $nameAttr = $name !== '' ? " name=\"" . Escaper::attr($name) . "\"" : '';

        return "<div class=\"w-full\">{$labelHtml}<div class=\"relative\">{$iconHtml}<input type=\"{$type}\" id=\"{$id}\"{$nameAttr}{$placeholderAttr}{$valueAttr} class=\"{$inputClasses}\"{$attrs}/></div>{$errorHtml}</div>";
    }
}
