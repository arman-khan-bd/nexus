<?php

declare(strict_types=1);

/**
 * NextPHP Shield v2 - Master Layout
 *
 * Features:
 * - Tailwind CSS with Shadcn HSL CSS Variables
 * - Dark & Light mode toggle with persistent state
 * - Dynamic CSP Nonce generation and header/meta injection
 * - Alpine.js + Alpine Morph plugin
 * - Lucide SVG Icons
 * - Zero-AJAX pushState SPA Engine (nexus-spa.js)
 */

$cspNonce = base64_encode(random_bytes(16));
$appName = \Core\Env::string('APP_NAME', 'Nexus');
$csrfToken = \Core\Security\Csrf::token();

if (!headers_sent()) {
    $cspHeader = "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval' 'nonce-" . $cspNonce . "' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https: blob:; connect-src 'self';";
    header($cspHeader);
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Immediate Theme Initialization Script (Prevents FOUC & Provides Global Toggle) -->
    <script nonce="<?= $cspNonce ?>">
        (function() {
            try {
                const saved = localStorage.getItem('theme');
                const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                const isDark = (saved === 'dark') || (!saved && prefersDark);
                if (isDark) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } catch (e) {}
        })();

        // Global toggleTheme function with zero-dependencies
        function toggleTheme() {
            const html = document.documentElement;
            const isDark = html.classList.toggle('dark');
            try {
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            } catch (e) {}
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark: isDark } }));
            return isDark;
        }
        window.toggleTheme = toggleTheme;

        // Dedicated click listener for theme toggle button using event delegation (single handler)
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('#theme-toggle-btn');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                window.toggleTheme();
            }
        });
    </script>

    <!-- Dynamic Content Security Policy (CSP) with Nonce -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-eval' 'nonce-<?= $cspNonce ?>' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https: blob:; connect-src 'self';">

    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="csp-nonce" content="<?= $cspNonce ?>">

    <!-- Shadcn UI HSL Design Tokens (Light & Dark Mode) -->
    <style nonce="<?= $cspNonce ?>">
        :root {
            --background: 0 0% 100%;
            --foreground: 222.2 84% 4.9%;
            --card: 0 0% 100%;
            --card-foreground: 222.2 84% 4.9%;
            --popover: 0 0% 100%;
            --popover-foreground: 222.2 84% 4.9%;
            --primary: 221.2 83.2% 53.3%;
            --primary-foreground: 210 40% 98%;
            --secondary: 210 40% 96.1%;
            --secondary-foreground: 222.2 47.4% 11.2%;
            --muted: 210 40% 96.1%;
            --muted-foreground: 215.4 16.3% 46.9%;
            --accent: 210 40% 96.1%;
            --accent-foreground: 222.2 47.4% 11.2%;
            --destructive: 0 84.2% 60.2%;
            --destructive-foreground: 210 40% 98%;
            --border: 214.3 31.8% 91.4%;
            --input: 214.3 31.8% 91.4%;
            --ring: 221.2 83.2% 53.3%;
            --radius: 0.75rem;
        }

        .dark {
            --background: 224 71% 4%;
            --foreground: 210 40% 98%;
            --card: 222.2 84% 4.9%;
            --card-foreground: 210 40% 98%;
            --popover: 222.2 84% 4.9%;
            --popover-foreground: 210 40% 98%;
            --primary: 217.2 91.2% 59.8%;
            --primary-foreground: 222.2 47.4% 11.2%;
            --secondary: 217.2 32.6% 17.5%;
            --secondary-foreground: 210 40% 98%;
            --muted: 217.2 32.6% 17.5%;
            --muted-foreground: 215 20.2% 65.1%;
            --accent: 217.2 32.6% 17.5%;
            --accent-foreground: 210 40% 98%;
            --destructive: 0 62.8% 30.6%;
            --destructive-foreground: 210 40% 98%;
            --border: 217.2 32.6% 17.5%;
            --input: 217.2 32.6% 17.5%;
            --ring: 224.3 76.3% 48%;
        }

        html.dark #theme-sun-icon { display: block !important; }
        html.dark #theme-moon-icon { display: none !important; }
        html:not(.dark) #theme-sun-icon { display: none !important; }
        html:not(.dark) #theme-moon-icon { display: block !important; }

        [x-cloak] { display: none !important; }
    </style>

    <!-- Tailwind CSS with Shadcn Color Mapping -->
    <script nonce="<?= $cspNonce ?>" src="https://cdn.tailwindcss.com"></script>
    <script nonce="<?= $cspNonce ?>">
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        background: 'hsl(var(--background))',
                        foreground: 'hsl(var(--foreground))',
                        card: {
                            DEFAULT: 'hsl(var(--card))',
                            foreground: 'hsl(var(--card-foreground))',
                        },
                        popover: {
                            DEFAULT: 'hsl(var(--popover))',
                            foreground: 'hsl(var(--popover-foreground))',
                        },
                        primary: {
                            DEFAULT: 'hsl(var(--primary))',
                            foreground: 'hsl(var(--primary-foreground))',
                        },
                        secondary: {
                            DEFAULT: 'hsl(var(--secondary))',
                            foreground: 'hsl(var(--secondary-foreground))',
                        },
                        muted: {
                            DEFAULT: 'hsl(var(--muted))',
                            foreground: 'hsl(var(--muted-foreground))',
                        },
                        accent: {
                            DEFAULT: 'hsl(var(--accent))',
                            foreground: 'hsl(var(--accent-foreground))',
                        },
                        destructive: {
                            DEFAULT: 'hsl(var(--destructive))',
                            foreground: 'hsl(var(--destructive-foreground))',
                        },
                        border: 'hsl(var(--border))',
                        input: 'hsl(var(--input))',
                        ring: 'hsl(var(--ring))',
                    },
                    borderRadius: {
                        lg: 'var(--radius)',
                        md: 'calc(var(--radius) - 2px)',
                        sm: 'calc(var(--radius) - 4px)',
                    }
                }
            }
        }
    </script>

    <!-- Alpine Morph Plugin & Alpine.js Core -->
    <script nonce="<?= $cspNonce ?>" defer src="https://cdn.jsdelivr.net/npm/@alpinejs/morph@3.x.x/dist/cdn.min.js"></script>
    <script nonce="<?= $cspNonce ?>" defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Lucide SVG Icons -->
    <script nonce="<?= $cspNonce ?>" src="https://unpkg.com/lucide@latest"></script>

    <!-- Nexus SPA Client Engine (pushState + Alpine Morph + Server Actions + Toasts) -->
    <script nonce="<?= $cspNonce ?>" defer src="/assets/js/nexus-spa.js"></script>
</head>
<body x-data="{ darkMode: true }" class="bg-background text-foreground min-h-screen antialiased flex flex-col selection:bg-primary selection:text-primary-foreground transition-colors duration-200">

    <!-- Header Navigation -->
    <header class="border-b border-border/80 bg-background/80 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
            <a href="/" data-nexus-link class="flex items-center gap-3 group">
                <div class="w-9 h-9 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary group-hover:scale-105 transition-transform shadow-sm">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                </div>
                <div class="flex items-baseline gap-1.5">
                    <span class="font-extrabold text-lg tracking-tight">nexus<span class="text-primary">.php</span></span>
                    <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-primary/10 text-primary border border-primary/20">v2.0</span>
                </div>
            </a>

            <nav class="flex items-center gap-5 text-sm font-medium">
                <a href="/" data-nexus-link class="text-muted-foreground hover:text-foreground transition-colors">Overview</a>
                <a href="/about" data-nexus-link class="text-muted-foreground hover:text-foreground transition-colors">Architecture</a>
                <a href="/docs" data-nexus-link class="text-muted-foreground hover:text-foreground transition-colors flex items-center gap-1.5 font-semibold text-primary">
                    <i data-lucide="book-open" class="w-4 h-4"></i>
                    Docs
                </a>

                <!-- Fortress Status Pill -->
                <span class="hidden sm:inline-flex text-xs px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 items-center gap-1.5 font-semibold">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    Fortress Armed
                </span>

                <!-- Dark / Light Mode Toggle Button -->
                <button id="theme-toggle-btn"
                        type="button"
                        class="w-9 h-9 rounded-lg border border-border bg-card flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-accent transition-colors cursor-pointer select-none"
                        title="Toggle Light / Dark Mode"
                        aria-label="Toggle Light / Dark Mode">
                    <!-- Sun Icon: Shown when in dark mode to switch to light -->
                    <svg id="theme-sun-icon" class="w-4 h-4 text-amber-400 pointer-events-none" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2"></path>
                        <path d="M12 20v2"></path>
                        <path d="m4.93 4.93 1.41 1.41"></path>
                        <path d="m17.66 17.66 1.41 1.41"></path>
                        <path d="M2 12h2"></path>
                        <path d="M20 12h2"></path>
                        <path d="m6.34 17.66-1.41 1.41"></path>
                        <path d="m19.07 4.93-1.41 1.41"></path>
                    </svg>
                    <!-- Moon Icon: Shown when in light mode to switch to dark -->
                    <svg id="theme-moon-icon" class="w-4 h-4 text-slate-700 pointer-events-none" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"></path>
                    </svg>
                </button>
            </nav>
        </div>
    </header>

    <!-- Page Content Viewport Container -->
    <main id="nexus-root" class="flex-1 max-w-6xl mx-auto px-6 py-10 w-full">
        <?= $children ?? '' ?>
    </main>

    <!-- Footer -->
    <footer class="border-t border-border py-8 bg-card/50 text-center text-xs text-muted-foreground">
        <div class="max-w-6xl mx-auto px-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <span class="font-semibold text-foreground">Nexus Framework</span>
                <span>&bull;</span>
                <span>cPanel Zero-SSH High-Security Platform</span>
            </div>
            <div class="flex items-center gap-3 font-mono text-[11px]">
                <a href="/docs" data-nexus-link class="text-primary hover:underline font-sans font-medium">Docs &amp; Guide</a>
                <span>&bull;</span>
                <span class="px-2 py-0.5 rounded bg-secondary border border-border">PHP <?= PHP_VERSION ?></span>
                <span class="px-2 py-0.5 rounded bg-secondary border border-border">CSP Nonce Active</span>
                <span class="px-2 py-0.5 rounded bg-secondary border border-border">0700 Isolation</span>
            </div>
        </div>
    </footer>

    <!-- Initialize Lucide Icons -->
    <script nonce="<?= $cspNonce ?>">
        (function() {
            function initIcons() {
                if (window.lucide && typeof window.lucide.createIcons === 'function') {
                    window.lucide.createIcons();
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initIcons);
            } else {
                initIcons();
            }
        })();
    </script>
</body>
</html>
