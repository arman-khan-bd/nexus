<?php

declare(strict_types=1);

namespace Components\Ui;

use Core\UI\Component;
use Core\Security\Escaper;

/**
 * Class Dialog
 *
 * Reactive Modal Dialog powered by Alpine.js & Tailwind CSS.
 * Provides accessible keyboard trap (ESC key), background blur backdrop,
 * and smooth transitions.
 */
class Dialog extends Component
{
    public function render(array $props, ?callable $slot = null): string
    {
        $title = $props['title'] ?? 'Modal Dialog';
        $description = $props['description'] ?? null;
        $triggerText = $props['triggerText'] ?? 'Open Dialog';
        $triggerIcon = $props['triggerIcon'] ?? null;
        $triggerVariant = $props['triggerVariant'] ?? 'default';

        $attrs = $this->renderAttributes($props, ['title', 'description', 'triggerText', 'triggerIcon', 'triggerVariant', 'class']);
        $body = $this->renderSlot($slot);

        $iconHtml = '';
        if ($triggerIcon) {
            $iconHtml = "<i data-lucide=\"" . Escaper::attr($triggerIcon) . "\" class=\"w-4 h-4\"></i>";
        }

        $descHtml = '';
        if ($description) {
            $descHtml = "<p class=\"text-sm text-muted-foreground mt-1\">" . Escaper::html($description) . "</p>";
        }

        return <<<HTML
<div x-data="{ open: false }" @keydown.escape.window="open = false" class="inline-block"{$attrs}>
    <!-- Trigger Button -->
    <button type="button" @click="open = true" class="inline-flex items-center gap-2 h-10 px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg text-sm font-medium transition cursor-pointer select-none">
        {$iconHtml}
        <span>{$triggerText}</span>
    </button>

    <!-- Modal Teleport / Backdrop -->
    <template x-teleport="body">
        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm"
             style="display: none;">
            
            <!-- Dialog Card -->
            <div @click.outside="open = false"
                 x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="relative w-full max-w-lg bg-card border border-border rounded-2xl p-6 shadow-2xl text-card-foreground">
                
                <!-- Close Button -->
                <button type="button" @click="open = false" class="absolute right-4 top-4 text-muted-foreground hover:text-foreground transition cursor-pointer">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <!-- Header -->
                <div class="mb-5 pr-8">
                    <h2 class="text-xl font-bold text-foreground tracking-tight">{$title}</h2>
                    {$descHtml}
                </div>

                <!-- Body Slot -->
                <div class="space-y-4">
                    {$body}
                </div>
            </div>
        </div>
    </template>
</div>
HTML;
    }
}
