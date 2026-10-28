<?php

declare(strict_types=1);

/**
 * Nexus Framework - System Architecture & Security Specs
 * Route: pages/about/page.php
 */
?>

<div class="space-y-12">
    <!-- Header Section with Back Navigation & Badges -->
    <div class="space-y-4">
        <div>
            <Button as="a" href="/" variant="ghost" size="sm" icon="arrow-left" data-nexus-link class="text-muted-foreground hover:text-foreground mb-3 -ml-2.5">
                Back to Overview
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <Badge variant="success" pulse>cPanel Production Ready</Badge>
            <Badge variant="secondary" icon="shield-check">Fortress Perimeter</Badge>
            <Badge variant="outline" icon="cpu">Zero Node.js Runtime</Badge>
        </div>

        <div class="space-y-3 max-w-4xl">
            <h1 class="text-3xl sm:text-5xl font-black tracking-tight text-foreground leading-tight">
                System Architecture &amp; Security Specs
            </h1>
            <p class="text-base sm:text-lg text-muted-foreground leading-relaxed max-w-3xl">
                Engineered specifically for cPanel and Apache shared hosting environments with zero daemon dependencies, strict memory constraints, and bank-grade cryptographic protections.
            </p>
        </div>
    </div>

    <!-- Architecture Grid: 4 Core Modules -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- 1. Dual-Tier WAF Perimeter -->
        <Card title="Layer 7 Dual-Tier WAF" icon="shield-check" description="Apache server-level and PHP kernel-level attack neutralization">
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground leading-relaxed">
                    Defense-in-depth is enforced through two non-overlapping security perimeters before request execution reaches application code:
                </p>

                <div class="space-y-3 text-sm">
                    <div class="p-4 rounded-xl border border-border bg-secondary/40 space-y-1.5">
                        <div class="font-semibold text-foreground flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                            Apache Layer (.htaccess)
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Neutralizes null-byte attacks, base64 exploit payloads, directory traversal, and SQLi vectors at the web server gate. Direct access to <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">/core</code>, <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">/storage</code>, and <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">.env</code> is rejected with an instant 403 Forbidden before PHP boots.
                        </p>
                    </div>

                    <div class="p-4 rounded-xl border border-border bg-secondary/40 space-y-1.5">
                        <div class="font-semibold text-foreground flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            PHP Kernel Layer (Firewall.php)
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Deep recursive scanning of <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">$_GET</code>, <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">$_POST</code>, <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">$_COOKIE</code>, and JSON payloads against OWASP Top 10 vectors with cryptographic tamper audit logging in <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">storage/logs/security.log</code>.
                        </p>
                    </div>
                </div>
            </div>
        </Card>

        <!-- 2. Shared Hosting Fortification -->
        <Card title="Shared Hosting Hardening" icon="server" description="Multi-tenant isolation engineered for low-cost shared environments">
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground leading-relaxed">
                    Shared cPanel servers present distinct security risks when frameworks use the shared <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">/tmp</code> directory for session storage:
                </p>

                <div class="space-y-3 text-sm">
                    <div class="p-4 rounded-xl border border-border bg-secondary/40 space-y-1.5">
                        <div class="font-semibold text-foreground flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            0700 Storage &amp; Session Isolation
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Bypasses vulnerable shared <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">/tmp</code> directories. Sessions reside under isolated <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">storage/sessions/</code> with strict <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">0700</code> owner-only POSIX permissions and tamper-evident authenticated headers.
                        </p>
                    </div>

                    <div class="p-4 rounded-xl border border-border bg-secondary/40 space-y-1.5">
                        <div class="font-semibold text-foreground flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                            Subnet &amp; UA Anti-Hijacking
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Active session guard cryptographically binds authentication tokens to client User-Agent fingerprints and <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">/24</code> IPv4 (or <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">/48</code> IPv6) subnets, destroying stolen session cookies immediately upon network change.
                        </p>
                    </div>
                </div>
            </div>
        </Card>

        <!-- 3. Zero-AJAX SPA Engine & Server Actions -->
        <Card title="Zero-AJAX SPA Architecture" icon="sparkles" description="Alpine Morph pushState bridge with zero Node.js tooling">
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground leading-relaxed">
                    Experience seamless Next.js App Router style transitions with zero build toolchains or npm dependencies:
                </p>

                <div class="space-y-3 text-sm">
                    <div class="p-4 rounded-xl border border-border bg-secondary/40 space-y-1.5">
                        <div class="font-semibold text-foreground flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            Instant Hover Prefetching
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Hovering over any <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">data-nexus-link</code> pre-loads the compiled route into an in-memory client cache with a 30s TTL, delivering sub-10ms perceived navigation transitions.
                        </p>
                    </div>

                    <div class="p-4 rounded-xl border border-border bg-secondary/40 space-y-1.5">
                        <div class="font-semibold text-foreground flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                            Surgical DOM Morphing
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Alpine Morph surgically updates only mutated DOM subtrees inside <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">#nexus-root</code>, preserving active Alpine state, focus positions, and scroll offsets without white-flash page reloads.
                        </p>
                    </div>
                </div>
            </div>
        </Card>

        <!-- 4. Cryptographic Core & Database Encryption -->
        <Card title="Cryptographic Kernel Specs" icon="lock" description="Libsodium XChaCha20-Poly1305 and AES-256 field encryption">
            <div class="space-y-4">
                <p class="text-sm text-muted-foreground leading-relaxed">
                    All cryptographic operations utilize authenticated AEAD ciphers validated against 256-bit entropy requirements:
                </p>

                <div class="space-y-3 text-sm">
                    <div class="p-4 rounded-xl border border-border bg-secondary/40 space-y-1.5">
                        <div class="font-semibold text-foreground flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            Transparent Model Field Encryption
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Sensitive database attributes (<code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">api_key</code>, <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">ssn</code>, <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">phone</code>) are encrypted at rest with nonces stored alongside ciphertext, decrypted automatically on model hydration.
                        </p>
                    </div>

                    <div class="p-4 rounded-xl border border-border bg-secondary/40 space-y-1.5">
                        <div class="font-semibold text-foreground flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                            Anti-DDoS Sliding Window Rate Limiting
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Atomic sliding window limiters backed by <code class="text-primary font-mono text-[11px] bg-muted px-1.5 py-0.5 rounded">flock()</code> file mutexes throttle volumetric floods and bot scanners with Cloudflare-style cryptographic clearance proof cookies.
                        </p>
                    </div>
                </div>
            </div>
        </Card>
    </div>

    <!-- Technical Specifications Table Card -->
    <Card title="Framework Technical Specifications" icon="cpu" description="Runtime benchmarks, protocol support and security certifications">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border text-xs uppercase text-muted-foreground tracking-wider">
                        <th class="py-3 px-4 font-semibold">Subsystem</th>
                        <th class="py-3 px-4 font-semibold">Implementation Engine</th>
                        <th class="py-3 px-4 font-semibold">Security Standard</th>
                        <th class="py-3 px-4 font-semibold">Performance Metric</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    <tr class="hover:bg-muted/30 transition-colors">
                        <td class="py-3.5 px-4 font-medium text-foreground">Routing Engine</td>
                        <td class="py-3.5 px-4 text-muted-foreground font-mono text-xs">AppRouter (File-system)</td>
                        <td class="py-3.5 px-4"><Badge variant="outline">Strict Regex Isolation</Badge></td>
                        <td class="py-3.5 px-4 font-mono text-xs text-emerald-600 dark:text-emerald-400 font-semibold">&lt;0.5ms Dispatch</td>
                    </tr>
                    <tr class="hover:bg-muted/30 transition-colors">
                        <td class="py-3.5 px-4 font-medium text-foreground">Cryptography</td>
                        <td class="py-3.5 px-4 text-muted-foreground font-mono text-xs">Libsodium XChaCha20-Poly1305</td>
                        <td class="py-3.5 px-4"><Badge variant="success">AEAD Authenticated</Badge></td>
                        <td class="py-3.5 px-4 font-mono text-xs text-emerald-600 dark:text-emerald-400 font-semibold">Hardware Accelerated</td>
                    </tr>
                    <tr class="hover:bg-muted/30 transition-colors">
                        <td class="py-3.5 px-4 font-medium text-foreground">Application Firewall</td>
                        <td class="py-3.5 px-4 text-muted-foreground font-mono text-xs">Dual-Tier (Apache + Core\Waf)</td>
                        <td class="py-3.5 px-4"><Badge variant="default">OWASP Top 10 Strict</Badge></td>
                        <td class="py-3.5 px-4 font-mono text-xs text-emerald-600 dark:text-emerald-400 font-semibold">&lt;1.2ms Full Scan</td>
                    </tr>
                    <tr class="hover:bg-muted/30 transition-colors">
                        <td class="py-3.5 px-4 font-medium text-foreground">Template Compiler</td>
                        <td class="py-3.5 px-4 text-muted-foreground font-mono text-xs">JsxCompiler Native PHP Cache</td>
                        <td class="py-3.5 px-4"><Badge variant="secondary">Context-Aware Escaping</Badge></td>
                        <td class="py-3.5 px-4 font-mono text-xs text-emerald-600 dark:text-emerald-400 font-semibold">Zero Node.js Runtime</td>
                    </tr>
                    <tr class="hover:bg-muted/30 transition-colors">
                        <td class="py-3.5 px-4 font-medium text-foreground">Rate Limiter</td>
                        <td class="py-3.5 px-4 text-muted-foreground font-mono text-xs">Sliding Window Atomic File Lock</td>
                        <td class="py-3.5 px-4"><Badge variant="warning">Anti-Brute &amp; DDoS</Badge></td>
                        <td class="py-3.5 px-4 font-mono text-xs text-emerald-600 dark:text-emerald-400 font-semibold">Zero Redis/Daemon Req</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</div>
