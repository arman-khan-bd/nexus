<?php

declare(strict_types=1);

/**
 * Nexus Framework - 404 Not Found Template
 * Route: pages/404.php
 */

$requestPath = $_SERVER['REQUEST_URI'] ?? '/unknown';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$timestamp = date('Y-m-d H:i:s T');
$incidentId = 'nex_' . substr(bin2hex(random_bytes(6)), 0, 10);

$rawErrorPayload = "### Nexus 404 Route Not Found\n"
    . "- **Status**: 404 Not Found\n"
    . "- **Incident ID**: `{$incidentId}`\n"
    . "- **Requested Route**: `{$requestPath}`\n"
    . "- **HTTP Method**: {$requestMethod}\n"
    . "- **Client IP**: {$clientIp}\n"
    . "- **Timestamp**: {$timestamp}\n"
    . "- **Framework**: Nexus v2.0\n";
?>

<div class="max-w-2xl mx-auto py-12 px-4 sm:px-6" x-data="{
    copied: false,
    rawText: <?= json_encode($rawErrorPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    copyError() {
        const text = this.rawText;
        const self = this;
        const markCopied = () => {
            self.copied = true;
            setTimeout(() => { self.copied = false; }, 2500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(markCopied).catch(() => self.fallbackCopy(text, markCopied));
        } else {
            self.fallbackCopy(text, markCopied);
        }
    },
    fallbackCopy(text, cb) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); cb(); } catch (e) {}
        document.body.removeChild(ta);
    }
}">
    <div class="rounded-2xl border border-border bg-card p-6 sm:p-8 shadow-2xl space-y-6">
        <!-- Header with Right-Side Copy Error Button -->
        <div class="flex items-center justify-between pb-4 border-b border-border gap-4 flex-wrap">
            <div class="flex items-center gap-2.5">
                <span class="px-2.5 py-1 text-xs font-mono font-bold rounded-md bg-secondary text-foreground border border-border">
                    nexus<span class="text-blue-500">.php</span>
                </span>
                <span class="px-2.5 py-1 text-xs font-mono font-bold rounded-md bg-red-500/10 text-red-400 border border-red-500/30">
                    404 Not Found
                </span>
            </div>

            <!-- Right-Side Copy Error Button -->
            <button
                type="button"
                @click="copyError()"
                :class="copied ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/40' : 'bg-secondary hover:bg-blue-600 text-foreground hover:text-white border-blue-500/40'"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 text-xs sm:text-sm font-semibold rounded-lg border transition-all duration-200 shadow-sm focus:outline-none"
                title="Copy route error to clipboard"
            >
                <template x-if="!copied">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                </template>
                <template x-if="copied">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </template>
                <span x-text="copied ? 'Copied to Clipboard!' : 'Copy Error'"></span>
            </button>
        </div>

        <!-- Hero Status -->
        <div class="flex items-baseline gap-4">
            <span class="text-5xl font-black font-mono tracking-tight text-red-500">404</span>
            <div>
                <h1 class="text-2xl font-bold text-foreground">Route Not Found</h1>
                <p class="text-sm text-muted-foreground mt-0.5">The requested page or endpoint could not be found.</p>
            </div>
        </div>

        <!-- Route Details Box (Click to Copy) -->
        <div @click="copyError()" class="p-3.5 rounded-xl bg-background hover:bg-secondary/40 hover:border-blue-500/50 cursor-pointer transition-colors border border-border font-mono text-xs sm:text-sm flex items-center justify-between gap-3" title="Click to copy route error">
            <div class="flex items-center gap-2.5 overflow-hidden">
                <span class="px-2 py-0.5 rounded bg-blue-500/10 text-blue-400 font-bold text-xs"><?= htmlspecialchars($requestMethod, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="text-muted-foreground truncate"><?= htmlspecialchars($requestPath, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground/60 hidden sm:inline font-mono">Incident #<?= htmlspecialchars($incidentId, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="text-xs text-blue-400/80 font-mono">Click to copy</span>
            </div>
        </div>

        <!-- Developer Hint -->
        <div class="p-3 rounded-lg border-l-4 border-blue-500 bg-secondary/30 text-xs text-muted-foreground leading-relaxed">
            <span class="font-semibold text-foreground">Developer Tip:</span> Create <code class="text-blue-400 font-mono">pages<?= htmlspecialchars($requestPath, ENT_QUOTES, 'UTF-8') ?>/page.php</code> to add this view or review your route definitions in <code class="text-blue-400 font-mono">core/Router/AppRouter.php</code>.
        </div>

        <!-- Action Links -->
        <div class="flex items-center gap-3 pt-2">
            <a href="/" data-nexus-link class="flex-1 text-center py-2.5 px-4 rounded-xl bg-primary hover:bg-primary/90 text-primary-foreground font-semibold text-sm transition-colors shadow">
                Return to Home
            </a>
            <a href="/docs" data-nexus-link class="py-2.5 px-4 rounded-xl bg-secondary hover:bg-secondary/80 text-secondary-foreground font-semibold text-sm transition-colors border border-border">
                Documentation
            </a>
            <button type="button" onclick="window.location.reload()" class="py-2.5 px-4 rounded-xl bg-secondary hover:bg-secondary/80 text-secondary-foreground font-semibold text-sm transition-colors border border-border">
                Reload
            </button>
        </div>
    </div>
</div>
