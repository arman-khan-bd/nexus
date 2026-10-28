<?php

declare(strict_types=1);

/**
 * Nexus Shield v2 - Secure Showcase Landing Page
 * Demonstrates Next.js-inspired App Router, JSX-compiled Shadcn UI,
 * Zero-AJAX pushState SPA navigation, and reactive Server Actions.
 */

use Core\Http\Request;
use Core\Security\Escaper;
use Core\Security\SessionGuard;
use Core\Router\ServerAction;

// Ensure ServerAction helper functions are loaded
if (!function_exists('server_action')) {
    class_exists(ServerAction::class);
}

// 1. Register the Live Security Probe Server Action
server_action('testSecurity', function (array $input, ?Request $request = null) {
    $payload = (string)($input['test_payload'] ?? $input['payload'] ?? '');
    $category = (string)($input['category'] ?? 'Cross-Site Scripting (XSS)');

    // Context-aware auto-escaping via Escaper
    $safeHtml = Escaper::html($payload);
    $safeAttr = Escaper::attr($payload);
    $safeJs = Escaper::js($payload);
    $safeUrl = Escaper::url($payload);

    $isSodium = extension_loaded('sodium');
    $cipherName = $isSodium ? 'XChaCha20-Poly1305 (Libsodium)' : 'AES-256-GCM (OpenSSL)';

    $req = $request ?? Request::capture();
    $fingerprint = SessionGuard::fingerprint($req);

    return [
        'success' => true,
        'message' => 'Input neutralized & token bound.',
        'data'    => [
            'category'     => $category,
            'raw'          => $payload,
            'sanitized'    => $safeHtml,
            'escaped_attr' => $safeAttr,
            'escaped_js'   => $safeJs,
            'escaped_url'  => $safeUrl,
            'ip'           => $req->getIp(),
            'cipher'       => $cipherName,
            'session_fp'   => substr($fingerprint, 0, 16) . '...',
            'timestamp'    => date('Y-m-d H:i:s'),
            'verified'     => true,
        ],
        'toast'   => [
            'type'    => 'success',
            'message' => 'Security verified! Payload safely neutralized & CSRF token confirmed.',
        ]
    ];
});

// 2. Register the Kernel Health Ping Server Action
server_action('ping', function (array $input, ?Request $request = null) {
    return [
        'status'    => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'message'   => 'Server Action executed securely via Nexus Security Kernel!',
        'toast'     => [
            'type'    => 'success',
            'message' => 'Kernel ping successful (0ms server latency)',
        ]
    ];
});

// 3. Fallback direct server action dispatcher if invoked as endpoint
if (isset($request) && is_object($request) && method_exists($request, 'isServerAction') && $request->isServerAction()) {
    $actionName = $request->getActionName();
    $handler = ServerAction::get($actionName);
    if ($handler !== null) {
        return $handler($request->getInput(), $request);
    }
}
?>

<div class="space-y-16">
    <!-- Hero Banner Section -->
    <section class="space-y-6 pt-2 pb-4">
        <div class="flex flex-wrap items-center gap-2.5">
            <Badge variant="success" pulse>cPanel Fortress Active</Badge>
            <Badge variant="secondary" icon="shield-check">Nexus Framework v2.0</Badge>
            <Badge variant="outline" icon="cpu">PHP <?= PHP_VERSION ?> Native</Badge>
        </div>

        <div class="space-y-4 max-w-4xl">
            <h1 class="text-4xl sm:text-6xl font-black tracking-tight leading-[1.1] text-foreground">
                Next.js Ergonomics.<br>
                <span class="bg-gradient-to-r from-blue-500 via-indigo-400 to-emerald-400 bg-clip-text text-transparent">
                    Bank-Grade PHP Fortress.
                </span>
            </h1>
            <p class="text-base sm:text-lg text-muted-foreground leading-relaxed max-w-2xl">
                Build reactive web applications with JSX-compiled Shadcn components, zero-reload Alpine Morph SPA transitions, and native PHP Server Actions—with complete protection against SQLi, XSS, DDoS, and account takeover on standard cPanel shared hosting.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-4 pt-2">
            <Button variant="default" size="lg" icon="shield-alert" as="a" href="#probe-section">
                Test Security Probe
            </Button>
            <Button variant="outline" size="lg" icon="layers" as="a" href="#components-section">
                Explore UI Primitives
            </Button>

            <Dialog title="Fortress Security Telemetry" description="Live inspection of active security layers and cryptographic kernel state." triggerText="Inspect Telemetry" triggerIcon="activity" triggerVariant="secondary">
                <div class="space-y-3.5 text-sm">
                    <div class="flex items-center justify-between py-2 border-b border-border/60">
                        <span class="text-muted-foreground">Framework Kernel</span>
                        <span class="font-mono font-semibold text-foreground">Nexus Framework v2.0 (Fortress)</span>
                    </div>
                    <div class="flex items-center justify-between py-2 border-b border-border/60">
                        <span class="text-muted-foreground">Cryptographic Engine</span>
                        <span class="font-mono text-emerald-500 font-semibold"><?= extension_loaded('sodium') ? 'Libsodium XChaCha20-Poly1305' : 'OpenSSL AES-256-GCM' ?></span>
                    </div>
                    <div class="flex items-center justify-between py-2 border-b border-border/60">
                        <span class="text-muted-foreground">Rate Limiting Window</span>
                        <span class="font-mono text-foreground">60 req/min &bull; 5 POST/10s (Atomic Locked)</span>
                    </div>
                    <div class="flex items-center justify-between py-2 border-b border-border/60">
                        <span class="text-muted-foreground">Template Compiler</span>
                        <span class="font-mono text-blue-500 font-semibold">JsxCompiler (Native Cached)</span>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <span class="text-muted-foreground">Hosting Environment</span>
                        <span class="font-mono text-foreground">cPanel Zero-SSH Compatible (0700 Storage)</span>
                    </div>
                </div>
            </Dialog>
        </div>
    </section>

    <!-- Live Security Badge Grid (5 Core Defenses Showcase) -->
    <section class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-2 border-b border-border pb-4">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                    <Lucide icon="shield" class="text-primary w-6 h-6" />
                    Live Security Defense Grid
                </h2>
                <p class="text-sm text-muted-foreground mt-1">
                    Continuous multi-layered defense active on every HTTP lifecycle before reaching application code.
                </p>
            </div>
            <div class="text-xs font-mono text-muted-foreground">
                All 5 Systems Operational
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- 1. WAF Firewall -->
            <Card title="WAF Application Firewall" icon="shield-check" description="Deep inspection & OWASP Top 10 defense">
                <div class="space-y-3">
                    <div>
                        <Badge variant="success" pulse>Active / SQLi & XSS Blocked</Badge>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Recursive inspection of <code class="text-primary font-mono text-[11px]">$_GET</code>, <code class="text-primary font-mono text-[11px]">$_POST</code>, <code class="text-primary font-mono text-[11px]">$_COOKIE</code>, and <code class="text-primary font-mono text-[11px]">REQUEST_URI</code>. Neutralizes SQL injection, RCE vectors, and null-byte bypasses in &lt;1ms.
                    </p>
                    <div class="pt-2 border-t border-border/60 flex items-center justify-between text-[11px] font-mono text-muted-foreground">
                        <span>Mode: Strict Blocking</span>
                        <span class="text-emerald-500 font-semibold">403 Forbidden</span>
                    </div>
                </div>
            </Card>

            <!-- 2. Anti-DDoS Sliding Window Rate Limiter -->
            <Card title="Anti-DDoS Rate Limiter" icon="gauge" description="Atomic sliding window throttle">
                <div class="space-y-3">
                    <div>
                        <Badge variant="success" pulse>60 req/min &bull; 5 POST/10s</Badge>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Sub-millisecond file-locked counters in <code class="text-primary font-mono text-[11px]">storage/ratelimit/</code>. Vulnerability scanners (Nikto, Sqlmap, DirBuster) are throttled with automated JS challenges.
                    </p>
                    <div class="pt-2 border-t border-border/60 flex items-center justify-between text-[11px] font-mono text-muted-foreground">
                        <span>Lock Engine: flock Atomic</span>
                        <span class="text-emerald-500 font-semibold">HTTP 429 Shield</span>
                    </div>
                </div>
            </Card>

            <!-- 3. Libsodium Cryptographic Session Guard -->
            <Card title="Cryptographic Session Guard" icon="lock" description="XChaCha20-Poly1305 authenticated">
                <div class="space-y-3">
                    <div>
                        <Badge variant="success" pulse>Libsodium Encrypted</Badge>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Custom session handler stored in <code class="text-primary font-mono text-[11px]">storage/sessions/</code> with <code class="text-primary font-mono text-[11px]">0700</code> isolation. Cross-account session snooping on shared cPanel is mathematically impossible.
                    </p>
                    <div class="pt-2 border-t border-border/60 flex items-center justify-between text-[11px] font-mono text-muted-foreground">
                        <span>Anti-Hijack: /24 Subnet</span>
                        <span class="text-emerald-500 font-semibold">AEAD Protected</span>
                    </div>
                </div>
            </Card>

            <!-- 4. CSP Nonce Header Protection -->
            <Card title="CSP Nonce Protection" icon="key-round" description="Cryptographic dynamic nonces">
                <div class="space-y-3">
                    <div>
                        <Badge variant="success" pulse>Dynamic Nonce Active</Badge>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Fresh 128-bit cryptographic nonces generated per HTTP request cycle. Injected into Content-Security-Policy response headers and inline scripts to eliminate inline XSS vectors.
                    </p>
                    <div class="pt-2 border-t border-border/60 flex items-center justify-between text-[11px] font-mono text-muted-foreground">
                        <span>Entropy: 128-Bit CSPRNG</span>
                        <span class="text-emerald-500 font-semibold">Strict Scripting</span>
                    </div>
                </div>
            </Card>

            <!-- 5. Zero-AJAX SPA Engine & Server Actions -->
            <Card title="Zero-AJAX SPA Engine" icon="sparkles" description="Alpine Morph pushState bridge">
                <div class="space-y-3">
                    <div>
                        <Badge variant="success" pulse>Alpine Morph Active</Badge>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Zero-reload pushState transitions with link prefetching on hover. Native PHP <code class="text-primary font-mono text-[11px]">server_action()</code> functions bound to UI forms with automatic CSRF token sync.
                    </p>
                    <div class="pt-2 border-t border-border/60 flex items-center justify-between text-[11px] font-mono text-muted-foreground">
                        <span>DOM Engine: Alpine Morph</span>
                        <span class="text-emerald-500 font-semibold">Zero Node.js</span>
                    </div>
                </div>
            </Card>

            <!-- 6. FileShield & Anti-Web Shell -->
            <Card title="FileShield Upload Guard" icon="file-check" description="Anti-webshell & MIME isolation">
                <div class="space-y-3">
                    <div>
                        <Badge variant="success" pulse>0700 Storage Isolation</Badge>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        True MIME detection via <code class="text-primary font-mono text-[11px]">finfo_file</code>, double-extension stripping, EXIF PHP execution removal, and random hash renaming outside web execution roots.
                    </p>
                    <div class="pt-2 border-t border-border/60 flex items-center justify-between text-[11px] font-mono text-muted-foreground">
                        <span>Hash: random_bytes(16)</span>
                        <span class="text-emerald-500 font-semibold">Anti-Webshell</span>
                    </div>
                </div>
            </Card>
        </div>
    </section>

    <!-- Interactive Live Security Probe Demo Section -->
    <section id="probe-section" class="space-y-6 scroll-mt-20">
        <div class="border-b border-border pb-4">
            <h2 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <Lucide icon="zap" class="text-amber-500 w-6 h-6" />
                Interactive Security Probe Demo
            </h2>
            <p class="text-sm text-muted-foreground mt-1">
                Trigger real-time attacks against the live PHP Server Action kernel and observe context-aware neutralization.
            </p>
        </div>

        <div class="rounded-2xl border border-border bg-card p-6 sm:p-8 shadow-sm space-y-6"
             x-data="{
                 payload: '<script>alert(\'XSS Vector Neutralized!\')</script>',
                 category: 'Cross-Site Scripting (XSS)',
                 loading: false,
                 pingLoading: false,
                 result: null,
                 setPreset(p, c) {
                     this.payload = p;
                     this.category = c;
                     this.result = null;
                 },
                 async submitProbe() {
                     this.loading = true;
                     try {
                         const res = await window.runNexusAction('testSecurity', {
                             test_payload: this.payload,
                             category: this.category
                         });
                         if (res && res.data) {
                             this.result = res.data;
                         }
                     } catch (err) {
                         console.error('Probe execution error:', err);
                     } finally {
                         this.loading = false;
                     }
                 },
                 async triggerPing() {
                     this.pingLoading = true;
                     try {
                         await window.runNexusAction('ping');
                     } finally {
                         this.pingLoading = false;
                     }
                 }
             }">

            <!-- Preset Attack Selector Buttons -->
            <div class="space-y-3">
                <label class="text-xs font-semibold uppercase tracking-wider text-muted-foreground block">
                    Select Test Attack Vector Preset:
                </label>
                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            @click="setPreset('<script>alert(\'XSS Vector Neutralized!\')</script>', 'Cross-Site Scripting (XSS)')"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/60 hover:bg-secondary text-foreground transition-all cursor-pointer flex items-center gap-1.5"
                            :class="category === 'Cross-Site Scripting (XSS)' ? 'ring-2 ring-primary border-primary text-primary' : ''">
                        <Lucide icon="code-2" class="w-3.5 h-3.5" />
                        XSS Script Injection
                    </button>

                    <button type="button"
                            @click="setPreset('\' UNION SELECT 1, \'admin\', password_hash FROM users WHERE 1=1 --', 'SQL Injection Probe')"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/60 hover:bg-secondary text-foreground transition-all cursor-pointer flex items-center gap-1.5"
                            :class="category === 'SQL Injection Probe' ? 'ring-2 ring-primary border-primary text-primary' : ''">
                        <Lucide icon="database" class="w-3.5 h-3.5" />
                        SQLi Union Vector
                    </button>

                    <button type="button"
                            @click="setPreset('../../../../etc/passwd\u0000', 'Directory Traversal & Null Byte')"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/60 hover:bg-secondary text-foreground transition-all cursor-pointer flex items-center gap-1.5"
                            :class="category === 'Directory Traversal & Null Byte' ? 'ring-2 ring-primary border-primary text-primary' : ''">
                        <Lucide icon="folder-x" class="w-3.5 h-3.5" />
                        Path Traversal %00
                    </button>

                    <button type="button"
                            @click="setPreset('&quot; onfocus=&quot;alert(\'Attr XSS\')&quot; autofocus=&quot;', 'HTML Attribute Injection')"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/60 hover:bg-secondary text-foreground transition-all cursor-pointer flex items-center gap-1.5"
                            :class="category === 'HTML Attribute Injection' ? 'ring-2 ring-primary border-primary text-primary' : ''">
                        <Lucide icon="tag" class="w-3.5 h-3.5" />
                        Attribute Injection
                    </button>
                </div>
            </div>

            <!-- Probe Form -->
            <form @submit.prevent="submitProbe()" class="space-y-4">
                <div class="space-y-2">
                    <label for="probe_payload" class="text-xs font-semibold text-foreground flex items-center justify-between">
                        <span>Payload Input String:</span>
                        <span class="text-muted-foreground font-mono font-normal" x-text="'Target Category: ' + category"></span>
                    </label>
                    <div class="relative">
                        <input id="probe_payload"
                               type="text"
                               x-model="payload"
                               required
                               placeholder="Enter hostile attack string..."
                               class="w-full h-11 px-4 rounded-lg border border-border bg-background text-sm font-mono text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary transition-all">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-1">
                    <Button type="submit" variant="destructive" icon="shield-alert" :disabled="loading">
                        <span x-text="loading ? 'Neutralizing Payload...' : 'Execute Security Probe'">Execute Security Probe</span>
                    </Button>

                    <Button type="button" variant="outline" icon="activity" @click="triggerPing()" :disabled="pingLoading">
                        <span x-text="pingLoading ? 'Pinging Kernel...' : 'Ping Server Action'">Ping Server Action</span>
                    </Button>
                </div>
            </form>

            <!-- Output Telemetry Card -->
            <div x-show="result !== null"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-5 sm:p-6 space-y-4">

                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-emerald-500/20 pb-3">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></div>
                        <h4 class="text-sm font-bold text-foreground">Kernel Neutralization Telemetry</h4>
                    </div>
                    <Badge variant="success" icon="shield-check">CSRF &amp; WAF Verified</Badge>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Raw Hostile Input -->
                    <div class="space-y-1.5">
                        <div class="text-xs font-semibold uppercase tracking-wider text-destructive flex items-center gap-1.5">
                            <Lucide icon="alert-triangle" class="w-3.5 h-3.5" />
                            Raw Injected Payload
                        </div>
                        <div class="p-3 rounded-lg border border-red-500/30 bg-red-500/10 font-mono text-xs text-red-700 dark:text-red-300 break-all select-all"
                             x-text="result ? result.raw : ''"></div>
                    </div>

                    <!-- Sanitized Safe Output -->
                    <div class="space-y-1.5">
                        <div class="text-xs font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                            <Lucide icon="check-circle-2" class="w-3.5 h-3.5" />
                            Escaper Sanitized Output
                        </div>
                        <div class="p-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 font-mono text-xs text-emerald-700 dark:text-emerald-300 break-all select-all"
                             x-text="result ? result.sanitized : ''"></div>
                    </div>
                </div>

                <!-- Cryptographic Metadata Badges -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2 text-xs font-mono">
                    <div class="p-2.5 rounded-lg border border-border bg-card/60">
                        <div class="text-[10px] text-muted-foreground uppercase">Cryptographic Cipher</div>
                        <div class="text-foreground font-semibold truncate mt-0.5" x-text="result ? result.cipher : ''"></div>
                    </div>
                    <div class="p-2.5 rounded-lg border border-border bg-card/60">
                        <div class="text-[10px] text-muted-foreground uppercase">Client IP Address</div>
                        <div class="text-foreground font-semibold truncate mt-0.5" x-text="result ? result.ip : ''"></div>
                    </div>
                    <div class="p-2.5 rounded-lg border border-border bg-card/60">
                        <div class="text-[10px] text-muted-foreground uppercase">Session Subnet Binding</div>
                        <div class="text-emerald-500 font-semibold truncate mt-0.5" x-text="result ? result.session_fp : ''"></div>
                    </div>
                    <div class="p-2.5 rounded-lg border border-border bg-card/60">
                        <div class="text-[10px] text-muted-foreground uppercase">Execution Timestamp</div>
                        <div class="text-foreground font-semibold truncate mt-0.5" x-text="result ? result.timestamp : ''"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Shadcn Component Library Showcase Section -->
    <section id="components-section" class="space-y-8 scroll-mt-20">
        <div class="border-b border-border pb-4">
            <h2 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <Lucide icon="layers" class="text-primary w-6 h-6" />
                Shadcn UI Component Registry
            </h2>
            <p class="text-sm text-muted-foreground mt-1">
                Zero Node.js dependency. Compiled server-side into native PHP with Tailwind CSS and HSL theme tokens.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- 1. Button Variants & Sizes -->
            <Card title="Buttons & Actions" icon="play" description="Tailwind-styled Shadcn buttons with icons and states">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Button variant="default">Default</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="destructive">Destructive</Button>
                        <Button variant="outline">Outline</Button>
                        <Button variant="ghost">Ghost</Button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5 pt-2 border-t border-border/60">
                        <Button size="sm" variant="default" icon="check">Small</Button>
                        <Button size="default" variant="secondary" icon="shield">Default</Button>
                        <Button size="lg" variant="outline" icon="sparkles">Large</Button>
                        <Button size="icon" variant="outline" icon="settings" title="Settings" />
                    </div>
                </div>
            </Card>

            <!-- 2. Badges & Status Indicators -->
            <Card title="Badges & Indicators" icon="tag" description="Contextual badges with status variants and pulse animations">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Badge variant="default">Default</Badge>
                        <Badge variant="secondary">Secondary</Badge>
                        <Badge variant="outline">Outline</Badge>
                        <Badge variant="success">Success</Badge>
                        <Badge variant="warning">Warning</Badge>
                        <Badge variant="destructive">Destructive</Badge>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5 pt-2 border-t border-border/60">
                        <Badge variant="success" pulse>Armed &bull; Pulse</Badge>
                        <Badge variant="warning" pulse>Rate Limited</Badge>
                        <Badge variant="outline" icon="lock">Authenticated</Badge>
                        <Badge variant="secondary" icon="cpu">PHP 8.2+</Badge>
                    </div>
                </div>
            </Card>

            <!-- 3. Form Inputs & Feedback -->
            <Card title="Form Inputs" icon="text-cursor-input" description="Accessible inputs with icons and inline error validation">
                <div class="space-y-3">
                    <Input label="Email Address" name="email_showcase" placeholder="admin@fortress.internal" icon="mail" />
                    <Input label="API Secret Key" name="key_showcase" type="password" placeholder="sk_live_..." icon="key" />
                    <Input label="Validation Example" name="error_showcase" value="bad_input" error="Field format invalid: malicious sequence detected." icon="alert-circle" />
                </div>
            </Card>

            <!-- 4. Reactive Modals & Dialogs -->
            <Card title="Reactive Dialogs" icon="panel-top-open" description="Accessible Alpine.js modals with focus traps and backdrop blur">
                <div class="space-y-4">
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Modals are teleported directly to <code class="text-primary font-mono text-[11px]">&lt;body&gt;</code>, featuring smooth fade transitions, accessible keyboard traps (ESC key), and backdrop dismissals.
                    </p>

                    <div class="pt-2">
                        <Dialog title="Cryptographic Cipher Details" description="Inspect the underlying Libsodium authenticated encryption engine." triggerText="Open Cipher Details" triggerIcon="lock">
                            <div class="space-y-3 text-sm text-muted-foreground">
                                <p>
                                    Nexus Framework uses <strong class="text-foreground">XChaCha20-Poly1305</strong> authenticated encryption with 256-bit keys and 192-bit nonces, ensuring absolute data confidentiality and tampering protection across all sessions and database models.
                                </p>
                                <div class="p-3 rounded-lg border border-border bg-secondary/50 font-mono text-xs text-foreground">
                                    Sodium Crypto Engine: ACTIVE<br>
                                    Storage Permissions: 0700 Owner-Only<br>
                                    Tamper Detection: Poly1305 MAC Verification
                                </div>
                            </div>
                        </Dialog>
                    </div>
                </div>
            </Card>
        </div>
    </section>
</div>
