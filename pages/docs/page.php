<?php

declare(strict_types=1);

/**
 * Nexus Framework - Official Advanced Documentation & Developer Hub
 * Designed for Human Developers & Autonomous AI Coding Agents.
 * Route: pages/docs/page.php
 */
?>

<div class="space-y-12" x-data="{
    activeTab: 'quickstart',
    codeCopied: false,
    searchQuery: '',
    copyCode(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text);
            this.codeCopied = true;
            setTimeout(() => { this.codeCopied = false; }, 2000);
        }
    }
}">
    <!-- Docs Hero Banner -->
    <div class="space-y-4 border-b border-border pb-8">
        <div class="flex flex-wrap items-center gap-2.5">
            <Badge variant="success" pulse>Nexus v2.0 Official</Badge>
            <Badge variant="secondary" icon="bot">Human &amp; AI Agent Ready</Badge>
            <Badge variant="outline" icon="shield">cPanel Hardened</Badge>
        </div>

        <div class="space-y-3 max-w-4xl">
            <h1 class="text-3xl sm:text-5xl font-black tracking-tight text-foreground leading-tight">
                Nexus Framework Documentation
            </h1>
            <p class="text-base sm:text-lg text-muted-foreground leading-relaxed max-w-3xl">
                The Next.js-inspired, JSX-compiled, ultra-secure PHP micro-framework engineered specifically for zero-daemon, shared cPanel hosting. Built for seamless collaboration between human software engineers and autonomous AI coding agents.
            </p>
        </div>

        <!-- Quick Jump Pills -->
        <div class="flex flex-wrap items-center gap-2 pt-2">
            <a href="#quickstart" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/50 hover:bg-secondary text-foreground transition-colors">⚡ Quickstart</a>
            <a href="#composer-publishing" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/50 hover:bg-secondary text-foreground transition-colors">📦 Composer Publishing</a>
            <a href="#app-router" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/50 hover:bg-secondary text-foreground transition-colors">🗂️ App Router &amp; JSX</a>
            <a href="#server-actions" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/50 hover:bg-secondary text-foreground transition-colors">⚡ Server Actions</a>
            <a href="#security-perimeter" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary/50 hover:bg-secondary text-foreground transition-colors">🛡️ Security Perimeter</a>
            <a href="#ai-agent-guide" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-primary/30 bg-primary/10 text-primary hover:bg-primary/20 transition-colors">🤖 AI Agent Protocol</a>
        </div>
    </div>

    <!-- Main Docs Content Layout: Sidebar + Content -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
        <!-- Sticky Sidebar Navigation -->
        <aside class="hidden lg:block lg:col-span-1 space-y-6 sticky top-20 self-start">
            <div class="p-4 rounded-xl border border-border bg-card space-y-4">
                <div class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Documentation Index</div>
                <nav class="space-y-1 text-sm font-medium">
                    <a href="#quickstart" class="block px-3 py-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">1. Quickstart &amp; Installation</a>
                    <a href="#composer-publishing" class="block px-3 py-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">2. Composer &amp; Packagist Guide</a>
                    <a href="#app-router" class="block px-3 py-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">3. Next.js-Style App Router</a>
                    <a href="#jsx-components" class="block px-3 py-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">4. JSX Compiler &amp; Shadcn UI</a>
                    <a href="#server-actions" class="block px-3 py-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">5. Server Actions &amp; SPA</a>
                    <a href="#security-perimeter" class="block px-3 py-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">6. 5-Layer Security Fortress</a>
                    <a href="#database-orm" class="block px-3 py-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">7. Database &amp; AEAD Models</a>
                    <a href="#cli-toolchain" class="block px-3 py-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors">8. Nexus CLI Toolchain</a>
                    <a href="#ai-agent-guide" class="block px-3 py-1.5 rounded-lg text-primary font-semibold hover:bg-primary/10 transition-colors">9. AI Agent Context Protocol</a>
                </nav>
            </div>

            <!-- Quick Telemetry Card -->
            <div class="p-4 rounded-xl border border-border bg-secondary/30 space-y-2 text-xs font-mono">
                <div class="text-muted-foreground uppercase text-[10px] font-bold">Runtime Profile</div>
                <div class="text-foreground font-semibold">PHP <?= PHP_VERSION ?> (CLI &amp; FPM)</div>
                <div class="text-muted-foreground">Zero Node.js &bull; 0700 Storage</div>
                <div class="text-emerald-500 font-semibold flex items-center gap-1.5 pt-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    Fortress Score: 100/100
                </div>
            </div>
        </aside>

        <!-- Documentation Body -->
        <main class="lg:col-span-3 space-y-16">
            <!-- SECTION 1: QUICKSTART -->
            <section id="quickstart" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 01</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="zap" class="text-primary w-6 h-6" />
                        Quickstart &amp; Installation
                    </h2>
                </div>

                <p class="text-sm sm:text-base text-muted-foreground leading-relaxed">
                    Nexus is distributed both as a reusable Composer project template and a zero-SSH GUI installer for cPanel shared hosting.
                </p>

                <!-- Method A: Composer create-project -->
                <div class="space-y-3">
                    <h3 class="text-base font-bold text-foreground">Option A: Single-Command Composer Installation</h3>
                    <p class="text-xs text-muted-foreground">Run this in your terminal to bootstrap a fresh project with isolated 0700 storage permissions, a secure 256-bit APP_KEY, and precompiled JSX caches:</p>
                    <div class="relative group">
                        <pre class="p-4 rounded-xl border border-border bg-muted/40 font-mono text-xs sm:text-sm text-foreground overflow-x-auto"><code>composer create-project nexus-lab/shield-boilerplate my-app
cd my-app
php nexus serve</code></pre>
                    </div>
                </div>

                <!-- Method B: cPanel Shared Hosting Zero-SSH Installation -->
                <div class="space-y-3 pt-2">
                    <h3 class="text-base font-bold text-foreground">Option B: cPanel Shared Hosting (Zero-SSH GUI Setup)</h3>
                    <p class="text-xs text-muted-foreground">For shared hosting accounts with no terminal or SSH access:</p>
                    <div class="p-4 rounded-xl border border-border bg-card space-y-3 text-xs sm:text-sm text-muted-foreground">
                        <div class="flex items-start gap-3">
                            <span class="w-5 h-5 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center shrink-0 text-xs">1</span>
                            <div>Download the release archive from GitHub or generate a production package via <code class="text-primary font-mono bg-muted px-1 py-0.5 rounded">php nexus build:cpanel</code>.</div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-5 h-5 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center shrink-0 text-xs">2</span>
                            <div>Upload and extract the archive directly into your cPanel <code class="text-primary font-mono bg-muted px-1 py-0.5 rounded">public_html/</code> directory via cPanel File Manager.</div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-5 h-5 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center shrink-0 text-xs">3</span>
                            <div>Navigate to <code class="text-primary font-mono bg-muted px-1 py-0.5 rounded">https://yourdomain.com/</code>. The framework automatically detects the uninstalled state and renders the interactive Shadcn Setup Wizard.</div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-5 h-5 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center shrink-0 text-xs">4</span>
                            <div>Fill in your MySQL credentials and Admin details. The installer runs database migrations transactionally, arms the <code class="text-primary font-mono bg-muted px-1 py-0.5 rounded">storage/app.installed</code> lockfile, and redirects you to the live application.</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECTION 2: COMPOSER & PACKAGIST PUBLISHING GUIDE -->
            <section id="composer-publishing" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 02</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="package" class="text-primary w-6 h-6" />
                        Composer &amp; Packagist Publishing Guide
                    </h2>
                </div>

                <p class="text-sm text-muted-foreground leading-relaxed">
                    To make your boilerplate installable via <code class="text-primary font-mono bg-muted px-1.5 py-0.5 rounded">composer create-project</code> and publish the cPanel installer as an independent library, follow this step-by-step release architecture.
                </p>

                <!-- Part 1: Publishing the cPanel Installer Library -->
                <Card title="Part 1: Publish nexus-lab/cpanel-installer Separately" icon="layers" description="Standalone library required by both boilerplate and custom projects">
                    <div class="space-y-4 text-xs sm:text-sm text-muted-foreground">
                        <p>1. <strong>Create GitHub Repository</strong>: Create a public repository named <code class="text-primary font-mono bg-muted px-1.5 py-0.5 rounded">nexus-lab/cpanel-installer</code> containing:</p>
                        <ul class="list-disc list-inside space-y-1 font-mono text-xs">
                            <li>composer.json (package name: nexus-lab/cpanel-installer, type: library)</li>
                            <li>src/Installer.php (Pre-req checks, installation engine)</li>
                            <li>src/DatabaseMigrator.php (Transactional SQL migration engine)</li>
                            <li>src/AdminProvisioner.php (Password hashing, APP_KEY generation)</li>
                            <li>src/views/wizard.php (Responsive Shadcn UI Setup Wizard)</li>
                        </ul>

                        <p>2. <strong>composer.json manifest for the Installer Library</strong>:</p>
                        <pre class="p-3 rounded-lg border border-border bg-muted/40 font-mono text-xs overflow-x-auto text-foreground"><code>{
    "name": "nexus-lab/cpanel-installer",
    "description": "Zero-SSH graphical web installer for Nexus applications on cPanel shared hosting.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "ext-pdo": "*",
        "ext-sodium": "*",
        "ext-json": "*"
    },
    "autoload": {
        "psr-4": {
            "Nexus\\Installer\\": "src/",
            "NextPHP\\Installer\\": "src/"
        }
    }
}</code></pre>

                        <p>3. <strong>Tag and Push Release</strong>:</p>
                        <pre class="p-3 rounded-lg border border-border bg-muted/40 font-mono text-xs overflow-x-auto text-foreground"><code>git tag -a v1.0.0 -m "Release v1.0.0: Stable cPanel Installer"
git push origin v1.0.0</code></pre>

                        <p>4. <strong>Submit to Packagist & Configure Auto-Update Hook</strong>:</p>
                        <p>Log in to <a href="https://packagist.org" target="_blank" class="text-primary underline">packagist.org</a>, submit <code class="font-mono">https://github.com/nexus-lab/cpanel-installer</code>. To fix the <em>"This package is not auto-updated"</em> warning, follow the Webhook setup below.</p>
                    </div>
                </Card>

                <!-- Part 2: Publishing the Boilerplate Package -->
                <Card title="Part 2: Publish nexus-lab/shield-boilerplate as create-project" icon="sparkles" description="Full framework template installable in a single command">
                    <div class="space-y-4 text-xs sm:text-sm text-muted-foreground">
                        <p>1. <strong>Create GitHub Repository</strong>: Create <code class="text-primary font-mono bg-muted px-1.5 py-0.5 rounded">nexus-lab/shield-boilerplate</code> containing the complete boilerplate codebase.</p>

                        <p>2. <strong>Root composer.json for the Boilerplate</strong>:</p>
                        <pre class="p-3 rounded-lg border border-border bg-muted/40 font-mono text-xs overflow-x-auto text-foreground"><code>{
    "name": "nexus-lab/shield-boilerplate",
    "description": "Nexus Framework - High-Security Next.js-inspired PHP Boilerplate",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "ext-pdo": "*",
        "ext-sodium": "*",
        "ext-json": "*",
        "nexus-lab/cpanel-installer": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Core\\": "core/",
            "App\\": "app/",
            "Components\\": "components/",
            "Nexus\\Installer\\": "packages/cpanel-installer/src/",
            "NextPHP\\Installer\\": "packages/cpanel-installer/src/"
        },
        "files": [
            "core/Router/helpers.php"
        ]
    },
    "bin": [
        "nexus",
        "bin/nexus",
        "nextphp",
        "bin/nextphp"
    ],
    "scripts": {
        "serve": [
            "php nexus serve"
        ],
        "post-create-project-cmd": [
            "Core\\Console\\Installer::postCreateProject"
        ]
    }
}</code></pre>

                        <p>3. <strong>Submit to Packagist</strong>: Submit <code class="font-mono">https://github.com/nexus-lab/shield-boilerplate</code> to Packagist. Once published, any developer worldwide can install the entire application with one command:</p>
                        <div class="p-3 rounded-lg bg-primary/10 border border-primary/20 font-mono text-xs text-primary font-bold">
                            composer create-project nexus-lab/shield-boilerplate my-application
                        </div>
                    </div>
                </Card>

                <!-- Part 3: GitHub Webhook Setup Guide -->
                <Card title="Fixing Packagist Auto-Update: GitHub Webhook Setup" icon="check-circle" description="How to dismiss the 'This package is not auto-updated' warning on Packagist">
                    <div class="space-y-3 text-xs sm:text-sm text-muted-foreground">
                        <p class="text-amber-500 font-medium flex items-center gap-2">
                            <Lucide icon="alert-triangle" class="w-4 h-4" />
                            Warning: "This package is not auto-updated. Please set up the GitHub Hook for Packagist..."
                        </p>
                        <p>Follow these 3 quick steps on GitHub to enable automatic sync whenever you push code or tags:</p>
                        <ol class="list-decimal list-inside space-y-2 pl-1">
                            <li>Get your <strong>Packagist API Token</strong> from your profile at <a href="https://packagist.org/profile/" target="_blank" class="text-primary underline">packagist.org/profile/</a>.</li>
                            <li>Go to your GitHub repo &rarr; <strong>Settings</strong> &rarr; <strong>Webhooks</strong> &rarr; <strong>Add webhook</strong>:
                                <ul class="list-disc list-inside space-y-1 font-mono text-xs pl-4 pt-1">
                                    <li><strong>Payload URL</strong>: <code>https://packagist.org/api/github?username=nexus-lab</code></li>
                                    <li><strong>Content type</strong>: <code>application/json</code></li>
                                    <li><strong>Secret</strong>: <em>[Paste your Packagist API Token]</em></li>
                                    <li><strong>Which events?</strong>: Select <em>"Just the push event"</em></li>
                                </ul>
                            </li>
                            <li>Click <strong>Add webhook</strong> on GitHub, then return to your Packagist package page and click the green <strong>"Update"</strong> button. The warning disappears immediately!</li>
                        </ol>
                    </div>
                </Card>
            </section>

            <!-- SECTION 3: APP ROUTER & FILE-SYSTEM ROUTING -->
            <section id="app-router" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 03</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="folder-tree" class="text-primary w-6 h-6" />
                        Next.js-Style App Router &amp; Layouts
                    </h2>
                </div>

                <p class="text-sm text-muted-foreground leading-relaxed">
                    Nexus implements file-system routing identical to the Next.js App Router. Directory hierarchies map directly to URL paths.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-border text-xs uppercase text-muted-foreground tracking-wider">
                                <th class="py-2.5 px-3 font-semibold">File System Path</th>
                                <th class="py-2.5 px-3 font-semibold">HTTP Route</th>
                                <th class="py-2.5 px-3 font-semibold">Role</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60 font-mono text-xs">
                            <tr>
                                <td class="py-2.5 px-3 text-primary">pages/layout.php</td>
                                <td class="py-2.5 px-3 text-muted-foreground">/* (All Routes)</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Master root layout (Navbar, Footer, CSP Nonce, Theme toggle)</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">pages/page.php</td>
                                <td class="py-2.5 px-3 text-foreground">/</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Homepage / landing view</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">pages/about/page.php</td>
                                <td class="py-2.5 px-3 text-foreground">/about</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">About / architecture page</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">pages/users/[id]/page.php</td>
                                <td class="py-2.5 px-3 text-foreground">/users/:id</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Dynamic parameter route ($params['id'])</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Code Example -->
                <div class="space-y-2">
                    <div class="text-xs font-semibold text-foreground">Creating a Route (Example: pages/dashboard/page.php)</div>
                    <pre class="p-4 rounded-xl border border-border bg-muted/40 font-mono text-xs overflow-x-auto text-foreground"><code>&lt;?php

declare(strict_types=1);

// Scaffold automatically via: php nextphp page:make dashboard
?&gt;

&lt;div class="space-y-6"&gt;
    &lt;div class="flex items-center justify-between"&gt;
        &lt;h1 class="text-2xl font-bold text-foreground"&gt;Admin Dashboard&lt;/h1&gt;
        &lt;Badge variant="success" pulse&gt;Active&lt;/Badge&gt;
    &lt;/div&gt;

    &lt;Card title="System Metrics" description="Real-time telemetry" icon="activity"&gt;
        &lt;p class="text-sm text-muted-foreground"&gt;Welcome back, <?= htmlspecialchars($user['name'] ?? 'Admin') ?>!&lt;/p&gt;
    &lt;/Card&gt;
&lt;/div&gt;</code></pre>
                </div>
            </section>

            <!-- SECTION 4: JSX COMPILER & SHADCN UI -->
            <section id="jsx-components" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 04</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="layers" class="text-primary w-6 h-6" />
                        JSX Compiler &amp; Shadcn UI Components
                    </h2>
                </div>

                <p class="text-sm text-muted-foreground leading-relaxed">
                    Nexus includes an embedded server-side JSX compiler (<code class="text-primary font-mono bg-muted px-1.5 py-0.5 rounded">Core\Compiler\JsxCompiler</code>) that transpiles JSX-style tags into native PHP function calls cached in <code class="text-primary font-mono bg-muted px-1.5 py-0.5 rounded">storage/cache/views/</code> with zero runtime compilation penalty.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card title="<Button>" icon="play" description="Variants: default, secondary, destructive, outline, ghost, link">
                        <pre class="p-2.5 rounded bg-muted/50 font-mono text-xs text-foreground overflow-x-auto"><code>&lt;Button variant="default" size="lg" icon="shield"&gt;
    Submit
&lt;/Button&gt;
&lt;Button as="a" href="/about" variant="outline"&gt;
    Learn More
&lt;/Button&gt;</code></pre>
                    </Card>

                    <Card title="<Card>" icon="square" description="Container with title, description, icon, slots, and footer">
                        <pre class="p-2.5 rounded bg-muted/50 font-mono text-xs text-foreground overflow-x-auto"><code>&lt;Card title="Database" icon="database" description="MySQL Connection"&gt;
    &lt;p class="text-sm text-muted-foreground"&gt;Status: Connected&lt;/p&gt;
&lt;/Card&gt;</code></pre>
                    </Card>

                    <Card title="<Badge>" icon="tag" description="Variants: success, warning, destructive, outline, pulse animation">
                        <pre class="p-2.5 rounded bg-muted/50 font-mono text-xs text-foreground overflow-x-auto"><code>&lt;Badge variant="success" pulse&gt;
    Operational
&lt;/Badge&gt;
&lt;Badge variant="outline" icon="cpu"&gt;
    PHP Native
&lt;/Badge&gt;</code></pre>
                    </Card>

                    <Card title="<Input>" icon="text-cursor-input" description="Accessible input with floating icon, label, and error validation">
                        <pre class="p-2.5 rounded bg-muted/50 font-mono text-xs text-foreground overflow-x-auto"><code>&lt;Input label="Email" name="email" icon="mail" 
       placeholder="user@example.com" 
       error="Invalid email address" /&gt;</code></pre>
                    </Card>
                </div>
            </section>

            <!-- SECTION 5: SERVER ACTIONS & SPA ENGINE -->
            <section id="server-actions" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 05</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="sparkles" class="text-primary w-6 h-6" />
                        Server Actions &amp; Zero-AJAX SPA Bridge
                    </h2>
                </div>

                <p class="text-sm text-muted-foreground leading-relaxed">
                    Server Actions let you write backend PHP functions directly inside your page files and trigger them seamlessly from client forms or Alpine.js with automatic cryptographic CSRF token injection.
                </p>

                <pre class="p-4 rounded-xl border border-border bg-muted/40 font-mono text-xs overflow-x-auto text-foreground"><code>// 1. Define Server Action at the top of your page.php
server_action('saveSettings', function (array $input, ?Request $request) {
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }

    // Save to database securely...
    return [
        'success' => true,
        'message' => 'Settings updated successfully!',
        'toast'   => ['type' => 'success', 'message' => 'Profile saved!']
    ];
});

// 2. Trigger from Alpine.js or Javascript anywhere
const response = await window.runNexusAction('saveSettings', { email: 'admin@domain.com' });</code></pre>
            </section>

            <!-- SECTION 6: SECURITY PERIMETER -->
            <section id="security-perimeter" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 06</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="shield-check" class="text-primary w-6 h-6" />
                        5-Layer Security Fortress Architecture
                    </h2>
                </div>

                <div class="space-y-4">
                    <div class="p-4 rounded-xl border border-border bg-card space-y-2">
                        <div class="font-bold text-foreground flex items-center gap-2">
                            <span class="w-6 h-6 rounded bg-primary/10 text-primary flex items-center justify-center text-xs font-bold">1</span>
                            Layer 7 Dual-Tier WAF (Apache .htaccess + Firewall.php)
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Multi-pass normalization neutralizes double URL encoding (<code class="font-mono">%252e%252e%252f</code>), null-bytes, and fullwidth unicode homoglyphs. Deep recursive scanning intercepts SQLi, XSS, RCE, Path Traversal, and PHP Object Injection with an instant 403 Forbidden.
                        </p>
                    </div>

                    <div class="p-4 rounded-xl border border-border bg-card space-y-2">
                        <div class="font-bold text-foreground flex items-center gap-2">
                            <span class="w-6 h-6 rounded bg-primary/10 text-primary flex items-center justify-center text-xs font-bold">2</span>
                            Anti-DDoS Sliding Window Rate Limiter (AntiDdos.php)
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Atomic file-locked counters (<code class="font-mono">storage/ratelimit/</code>) throttle global floods (60 req/min) and sensitive brute force (5 POST/10s). Local development IPs (<code class="font-mono">127.0.0.1, ::1</code>) and <code class="font-mono">APP_ENV=local</code> are automatically bypassed.
                        </p>
                    </div>

                    <div class="p-4 rounded-xl border border-border bg-card space-y-2">
                        <div class="font-bold text-foreground flex items-center gap-2">
                            <span class="w-6 h-6 rounded bg-primary/10 text-primary flex items-center justify-center text-xs font-bold">3</span>
                            Libsodium Cryptographic Session Guard (SessionGuard.php)
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Session data on disk is encrypted using <code class="font-mono">XChaCha20-Poly1305</code> AEAD with 0700 file isolation, completely eliminating cross-user session snooping on shared cPanel hosting. Sessions rotate every 10 minutes and bind to client subnet fingerprints.
                        </p>
                    </div>

                    <div class="p-4 rounded-xl border border-border bg-card space-y-2">
                        <div class="font-bold text-foreground flex items-center gap-2">
                            <span class="w-6 h-6 rounded bg-primary/10 text-primary flex items-center justify-center text-xs font-bold">4</span>
                            Dynamic CSP Nonce Injection (128-bit CSPRNG)
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Every HTTP request generates a unique 128-bit cryptographic nonce (<code class="font-mono">$cspNonce</code>) injected into both the <code class="font-mono">Content-Security-Policy</code> response header and inline script/style tags to eliminate cross-site scripting.
                        </p>
                    </div>

                    <div class="p-4 rounded-xl border border-border bg-card space-y-2">
                        <div class="font-bold text-foreground flex items-center gap-2">
                            <span class="w-6 h-6 rounded bg-primary/10 text-primary flex items-center justify-center text-xs font-bold">5</span>
                            FileShield &amp; Upload Execution Lock (FileShield.php)
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Uploads undergo true MIME magic-byte verification via <code class="font-mono">finfo_file</code>, stripping fake extensions (<code class="font-mono">shell.php.jpg</code>), stripping EXIF payloads, and renaming files to random 128-bit hashes stored in protected <code class="font-mono">storage/uploads/</code> directories.
                        </p>
                    </div>
                </div>
            </section>

            <!-- SECTION 7: DATABASE & AEAD MODELS -->
            <section id="database-orm" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 07</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="database" class="text-primary w-6 h-6" />
                        Database ORM &amp; AEAD Field Encryption
                    </h2>
                </div>

                <p class="text-sm text-muted-foreground leading-relaxed">
                    Nexus provides a lightweight ActiveRecord ORM (<code class="text-primary font-mono bg-muted px-1.5 py-0.5 rounded">Core\Database\Model</code>) with transparent Libsodium field-level encryption for sensitive columns.
                </p>

                <pre class="p-4 rounded-xl border border-border bg-muted/40 font-mono text-xs overflow-x-auto text-foreground"><code>namespace App\Models;

use Core\Database\Model;

class User extends Model
{
    protected static string $table = 'users';

    // Transparently encrypted at rest with XChaCha20-Poly1305
    protected array $encrypted = [
        'api_key',
        'phone',
        'ssn',
    ];

    protected array $hidden = [
        'password',
    ];
}

// Usage:
$user = User::create([
    'name'     => 'Alice',
    'email'    => 'alice@domain.com',
    'api_key'  => 'sk_live_secret_key_12345', // Encrypted in DB!
    'password' => password_hash('Pass123', PASSWORD_BCRYPT),
]);

// Transparent decryption on read:
echo $user->api_key; // Prints 'sk_live_secret_key_12345'</code></pre>
            </section>

            <!-- SECTION 8: CLI TOOLCHAIN -->
            <section id="cli-toolchain" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 08</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="terminal" class="text-primary w-6 h-6" />
                        Nexus CLI Toolchain Reference
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-border text-xs uppercase text-muted-foreground tracking-wider">
                                <th class="py-2.5 px-3 font-semibold">Command</th>
                                <th class="py-2.5 px-3 font-semibold">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60 font-mono text-xs">
                            <tr>
                                <td class="py-2.5 px-3 text-primary">php nexus serve [port]</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Start local built-in development server (default port: 8000)</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">php nexus page:make &lt;route&gt;</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Scaffold Next.js-style page and optional layout (--layout)</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">php nexus cache:compile</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Pre-compile all JSX templates into storage/cache/views/</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">php nexus cache:clear</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Clear compiled JSX views and unban/purge rate limit caches</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">php nexus security:audit</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Perform automated 7-point security inspection &amp; fortress score</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">php nexus build:cpanel</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Generate production-ready clean .zip archive for cPanel public_html</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">php nexus key:generate</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">Regenerate authenticated 256-bit Libsodium APP_KEY</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 px-3 text-primary">php nexus routes</td>
                                <td class="py-2.5 px-3 font-sans text-muted-foreground">List all registered file-system routes and layouts</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- SECTION 9: AI AGENT CONTEXT PROTOCOL -->
            <section id="ai-agent-guide" class="space-y-6 scroll-mt-20">
                <div class="space-y-2 border-b border-border pb-3">
                    <div class="text-xs font-mono text-primary font-semibold uppercase tracking-wider">Section 09</div>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground flex items-center gap-2">
                        <Lucide icon="bot" class="text-primary w-6 h-6" />
                        AI Agent Context &amp; Prompting Protocol
                    </h2>
                </div>

                <p class="text-sm text-muted-foreground leading-relaxed">
                    Paste the following prompt block into your AI Coding Agent (Cursor, Windsurf, Claude Code, GitHub Copilot, or Antigravity) to ensure it understands the exact architectural rules, strict typing contracts, and directory conventions of the Nexus Framework:
                </p>

                <!-- Copyable AI Agent System Prompt -->
                <div class="relative rounded-2xl border border-primary/30 bg-card p-6 space-y-4">
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <div class="flex items-center gap-2 text-xs font-bold uppercase text-primary tracking-wider">
                            <Lucide icon="sparkles" class="w-4 h-4" />
                            LLM System Prompt / Agent Rules Context
                        </div>
                        <button type="button"
                                @click="copyCode(document.getElementById('ai-agent-prompt-block').innerText)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-border bg-secondary hover:bg-secondary/80 text-foreground transition-colors cursor-pointer">
                            <Lucide icon="copy" class="w-3.5 h-3.5" />
                            <span x-text="codeCopied ? 'Copied to Clipboard!' : 'Copy AI Prompt'">Copy AI Prompt</span>
                        </button>
                    </div>

                    <pre id="ai-agent-prompt-block" class="p-4 rounded-xl border border-border bg-muted/30 font-mono text-xs text-foreground overflow-x-auto leading-relaxed select-all"># NEXUS FRAMEWORK AI AGENT CODING RULES & ARCHITECTURE CONTRACT

You are an expert engineer programming in the Nexus Framework.
Nexus is a Next.js-inspired, JSX-compiled, ultra-secure PHP micro-framework engineered for zero-daemon cPanel shared hosting (public_html root compatible, zero Node.js).

## STRICT CODING RULES FOR AI AGENTS:
1. STRICT TYPING: Always declare strict types at the very top of EVERY PHP file:
   &lt;?php
   declare(strict_types=1);

2. APP ROUTER FILE-SYSTEM CONVENTIONS:
   - Root layout: pages/layout.php
   - Page routes: pages/[route]/page.php (e.g. pages/about/page.php maps to /about)
   - Dynamic parameters: pages/users/[id]/page.php extracts $params['id']
   - Links: Always use data-nexus-link on &lt;a&gt; tags to enable zero-reload pushState SPA transitions:
     &lt;a href="/about" data-nexus-link&gt;About&lt;/a&gt;

3. SERVER ACTIONS CONVENTION:
   - Define actions at the top of page.php using server_action():
     server_action('actionName', function (array $input, ?Request $request) {
         return ['success' =&gt; true, 'data' =&gt; [...], 'toast' =&gt; ['type' =&gt; 'success', 'message' =&gt; 'Saved!']];
     });
   - Client triggers actions via: window.runNexusAction('actionName', payload);

4. JSX & SHADCN UI COMPONENTS:
   - Use built-in UI primitives: &lt;Button&gt;, &lt;Card&gt;, &lt;Badge&gt;, &lt;Input&gt;, &lt;Dialog&gt;, &lt;Link&gt;, &lt;Form&gt;, &lt;Lucide&gt;.
   - Use adaptive Tailwind CSS tokens: text-foreground, text-muted-foreground, bg-background, bg-card, border-border, bg-secondary. Never hardcode dark-only colors (e.g. text-white or bg-slate-900) so light mode functions flawlessly.

5. SECURITY CONTRACTS:
   - Context-Aware Escaping: Always use Core\Security\Escaper::html(), ::attr(), ::js(), ::url().
   - Never touch shared /tmp: Sessions are stored in storage/sessions/ with 0700 permissions.
   - Sensitive columns on Models must be listed in protected array $encrypted = ['column_name'] for automatic Libsodium XChaCha20-Poly1305 authenticated encryption.
   - Run 'php nexus cache:compile' after altering JSX pages to regenerate compiled templates.</pre>
                </div>
            </section>
        </main>
    </div>
</div>
