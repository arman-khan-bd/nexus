/**
 * Nexus / NextPHP Zero-AJAX SPA Bridge
 *
 * Provides:
 * 1. pushState SPA navigation with zero full-page reloads
 * 2. Instant route prefetching on link hover (sub-10ms perceived transitions)
 * 3. Alpine Morph surgical DOM updates of #nexus-root
 * 4. Automatic Server Action interception with CSRF tokens
 * 5. Automatic button loading spinners and state management
 * 6. Shadcn-styled Toast notification system
 */

(function () {
    'use strict';

    // In-memory prefetch cache (url -> { html, title, timestamp })
    const prefetchCache = new Map();
    const PREFETCH_TTL = 30000; // 30 seconds

    /**
     * Get active CSRF token from meta tag or cookie.
     */
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }
        return '';
    }

    /**
     * Set CSRF token in meta tag.
     */
    function setCsrfToken(token) {
        if (!token) return;
        let meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta) {
            meta = document.createElement('meta');
            meta.setAttribute('name', 'csrf-token');
            document.head.appendChild(meta);
        }
        meta.setAttribute('content', token);
    }

    /**
     * Extract title from HTML string or response.
     */
    function extractTitle(html) {
        const match = html.match(/<title[^>]*>([^<]+)<\/title>/i);
        return match ? match[1].trim() : document.title;
    }

    /**
     * Extract the #nexus-root element from incoming HTML.
     */
    function extractNexusRoot(html) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        return doc.querySelector('#nexus-root');
    }

    /**
     * Re-initialize Lucide SVG icons.
     */
    function refreshIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            try {
                window.lucide.createIcons();
            } catch (e) {
                // Silently pass icon rendering errors
            }
        }
    }

    /**
     * Smooth SPA Navigation.
     */
    async function navigateTo(url, pushState = true) {
        const targetUrl = new URL(url, window.location.href);

        // Don't navigate if same full URL
        if (targetUrl.href === window.location.href && !targetUrl.hash) {
            return;
        }

        window.dispatchEvent(new CustomEvent('nexus:navigating', { detail: { url: targetUrl.href } }));

        let content = null;
        let title = null;

        // 1. Check Prefetch Cache
        const cached = prefetchCache.get(targetUrl.pathname + targetUrl.search);
        if (cached && (Date.now() - cached.timestamp) < PREFETCH_TTL) {
            content = cached.content;
            title = cached.title;
        }

        // 2. Fetch if not cached
        if (!content) {
            try {
                const response = await fetch(targetUrl.href, {
                    method: 'GET',
                    headers: {
                        'X-NextPHP-SPA': 'true',
                        'X-Nexus-SPA': 'true',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html, application/xhtml+xml',
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    window.location.href = targetUrl.href;
                    return;
                }

                // Check for redirect header
                const redirect = response.headers.get('X-NextPHP-Redirect') || response.headers.get('X-Nexus-Redirect');
                if (redirect) {
                    navigateTo(redirect, true);
                    return;
                }

                // Update CSRF token if sent in headers
                const newCsrf = response.headers.get('X-CSRF-TOKEN');
                if (newCsrf) {
                    setCsrfToken(newCsrf);
                }

                title = response.headers.get('X-NextPHP-Title') || null;
                const html = await response.text();
                content = html;

                if (!title) {
                    title = extractTitle(html);
                }
            } catch (err) {
                console.warn('[Nexus SPA] Navigation fetch failed, falling back to full load:', err);
                window.location.href = targetUrl.href;
                return;
            }
        }

        // 3. Morph target DOM
        const currentRoot = document.querySelector('#nexus-root');
        if (!currentRoot) {
            window.location.href = targetUrl.href;
            return;
        }

        const newRoot = extractNexusRoot(content);
        const replacement = newRoot || content;

        if (window.Alpine && typeof window.Alpine.morph === 'function') {
            if (newRoot) {
                window.Alpine.morph(currentRoot, newRoot);
            } else {
                currentRoot.innerHTML = content;
            }
        } else {
            if (newRoot) {
                currentRoot.innerHTML = newRoot.innerHTML;
            } else {
                currentRoot.innerHTML = content;
            }
        }

        // 4. Update Title and History
        if (title) {
            document.title = title;
        }

        if (pushState) {
            window.history.pushState({ url: targetUrl.href }, '', targetUrl.href);
        }

        // 5. Scroll restoration
        if (targetUrl.hash) {
            const anchor = document.querySelector(targetUrl.hash);
            if (anchor) {
                anchor.scrollIntoView({ behavior: 'smooth' });
            }
        } else {
            window.scrollTo({ top: 0, behavior: 'instant' });
        }

        // 6. Refresh Icons and trigger hooks
        refreshIcons();
        window.dispatchEvent(new CustomEvent('nexus:navigated', { detail: { url: targetUrl.href } }));
    }

    /**
     * Prefetch a URL in the background.
     */
    async function prefetch(url) {
        try {
            const parsed = new URL(url, window.location.href);
            // Only prefetch same-origin routes
            if (parsed.origin !== window.location.origin) return;

            const key = parsed.pathname + parsed.search;
            if (prefetchCache.has(key)) return;

            const res = await fetch(parsed.href, {
                method: 'GET',
                headers: {
                    'X-NextPHP-SPA': 'true',
                    'X-Nexus-SPA': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                },
                credentials: 'same-origin'
            });

            if (res.ok) {
                const html = await res.text();
                const title = res.headers.get('X-NextPHP-Title') || extractTitle(html);
                prefetchCache.set(key, {
                    content: html,
                    title: title,
                    timestamp: Date.now()
                });
            }
        } catch (e) {
            // Silently skip prefetch failures
        }
    }

    /**
     * Toast Notification Manager
     */
    const NexusToast = {
        container: null,

        ensureContainer() {
            if (!this.container || !document.body.contains(this.container)) {
                this.container = document.createElement('div');
                this.container.id = 'nexus-toast-container';
                this.container.className = 'fixed bottom-5 right-5 z-[9999] flex flex-col gap-2.5 max-w-sm w-full pointer-events-none';
                document.body.appendChild(this.container);
            }
            return this.container;
        },

        show(message, type = 'default', duration = 4000) {
            const container = this.ensureContainer();

            const toast = document.createElement('div');
            toast.className = 'pointer-events-auto transform transition-all duration-300 ease-out translate-x-full opacity-0 flex items-start gap-3 p-4 rounded-xl shadow-2xl border text-sm backdrop-blur-md';

            // Theme styling based on type
            let bgClasses = 'bg-slate-900/95 border-slate-800 text-slate-100';
            let iconSvg = '<svg class="w-5 h-5 text-blue-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-width="2" d="M12 16v-4m0-4h.01"/></svg>';

            if (type === 'success') {
                bgClasses = 'bg-emerald-950/95 border-emerald-800/80 text-emerald-100';
                iconSvg = '<svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>';
            } else if (type === 'error' || type === 'destructive') {
                bgClasses = 'bg-red-950/95 border-red-800/80 text-red-100';
                iconSvg = '<svg class="w-5 h-5 text-red-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>';
            } else if (type === 'warning') {
                bgClasses = 'bg-amber-950/95 border-amber-800/80 text-amber-100';
                iconSvg = '<svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
            }

            toast.className += ' ' + bgClasses;

            toast.innerHTML = `
                ${iconSvg}
                <div class="flex-1 font-medium leading-tight">${message}</div>
                <button type="button" class="text-slate-400 hover:text-white transition-colors cursor-pointer -mr-1 -mt-1 p-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            `;

            container.appendChild(toast);

            // Animate in
            requestAnimationFrame(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
                toast.classList.add('translate-x-0', 'opacity-100');
            });

            // Dismiss handler
            const dismiss = () => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            };

            toast.querySelector('button').addEventListener('click', dismiss);
            setTimeout(dismiss, duration);
        }
    };

    /**
     * Attach spinner to an interactive element (button).
     */
    function setButtonLoading(button, isLoading) {
        if (!button) return;

        if (isLoading) {
            button.disabled = true;
            button.dataset.nexusOriginalHtml = button.innerHTML;
            button.innerHTML = `
                <svg class="animate-spin -ml-0.5 mr-2 h-4 w-4 text-current inline" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span>Processing...</span>
            `;
        } else {
            button.disabled = false;
            if (button.dataset.nexusOriginalHtml) {
                button.innerHTML = button.dataset.nexusOriginalHtml;
                delete button.dataset.nexusOriginalHtml;
            }
            refreshIcons();
        }
    }

    /**
     * Execute a Server Action.
     *
     * @param {string} actionName
     * @param {Object|FormData} payload
     * @param {Object} options
     * @returns {Promise<any>}
     */
    async function runServerAction(actionName, payload = {}, options = {}) {
        const csrfToken = getCsrfToken();
        const actionUrl = options.url || window.location.href;

        let body;
        const headers = {
            'X-NextPHP-Action': actionName,
            'X-Nexus-Action': actionName,
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        };

        if (payload instanceof FormData) {
            payload.set('_action', actionName);
            if (csrfToken && !payload.has('_token')) {
                payload.set('_token', csrfToken);
            }
            body = payload;
            // Let browser calculate multipart boundary for FormData
        } else {
            headers['Content-Type'] = 'application/json';
            const jsonPayload = {
                ...payload,
                _action: actionName,
                _token: csrfToken,
            };
            body = JSON.stringify(jsonPayload);
        }

        try {
            const res = await fetch(actionUrl, {
                method: 'POST',
                headers: headers,
                body: body,
                credentials: 'same-origin'
            });

            // Update CSRF token if returned in header
            const newCsrf = res.headers.get('X-CSRF-TOKEN');
            if (newCsrf) {
                setCsrfToken(newCsrf);
            }

            const isJson = (res.headers.get('content-type') || '').includes('application/json');
            let data;

            if (isJson) {
                data = await res.json();
            } else {
                const text = await res.text();
                // If HTML DOM morph response
                if (res.headers.get('X-NextPHP-Morph') === 'true' || res.headers.get('X-Nexus-Morph') === 'true') {
                    const currentRoot = document.querySelector('#nexus-root');
                    if (currentRoot) {
                        const newRoot = extractNexusRoot(text);
                        if (window.Alpine && typeof window.Alpine.morph === 'function') {
                            window.Alpine.morph(currentRoot, newRoot || text);
                        } else {
                            currentRoot.innerHTML = newRoot ? newRoot.innerHTML : text;
                        }
                        refreshIcons();
                    }
                    return { success: true, morph: true };
                }
                data = { success: res.ok, raw: text };
            }

            // Handle UI Toast Notifications
            if (data.toast) {
                const toastMsg = typeof data.toast === 'object' ? data.toast.message : data.toast;
                const toastType = typeof data.toast === 'object' ? data.toast.type : (data.success ? 'success' : 'error');
                NexusToast.show(toastMsg, toastType);
            } else if (data.message && data.success) {
                NexusToast.show(data.message, 'success');
            } else if (data.error) {
                NexusToast.show(data.message || data.error, 'error');
            }

            // Handle Server-Requested Redirects
            if (data.redirect) {
                navigateTo(data.redirect, true);
            }

            return data;
        } catch (err) {
            console.error('[Nexus Server Action] Execution failed:', err);
            NexusToast.show('Network error while processing request.', 'error');
            throw err;
        }
    }

    /**
     * Initialize Global Listeners.
     */
    document.addEventListener('DOMContentLoaded', () => {
        refreshIcons();

        // 1. Intercept SPA Navigation clicks
        document.body.addEventListener('click', (e) => {
            const link = e.target.closest('a[data-nexus-link], a[data-nextphp-link]');
            if (!link) return;

            // Ignore external or modified clicks
            if (link.target || link.hasAttribute('download') || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
                return;
            }

            const href = link.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) {
                return;
            }

            e.preventDefault();
            navigateTo(href);
        });

        // 2. Prefetch routes on hover
        document.body.addEventListener('mouseover', (e) => {
            const link = e.target.closest('a[data-nexus-link], a[data-nextphp-link]');
            if (!link || link.target) return;

            const href = link.getAttribute('href');
            if (href && !href.startsWith('#') && !href.startsWith('mailto:')) {
                prefetch(href);
            }
        });

        // 3. Intercept Form Server Actions
        document.body.addEventListener('submit', async (e) => {
            const form = e.target.closest('form');
            if (!form) return;

            const actionName = form.dataset.nexusAction ||
                               form.dataset.nextphpAction ||
                               form.dataset.action ||
                               form.querySelector('input[name="_action"]')?.value;

            if (!actionName) return;

            e.preventDefault();

            const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            setButtonLoading(submitBtn, true);

            const formData = new FormData(form);

            try {
                await runServerAction(actionName, formData, {
                    url: form.getAttribute('action') || window.location.href
                });
            } finally {
                setButtonLoading(submitBtn, false);
            }
        });

        // 4. Intercept Button Server Actions
        document.body.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-nexus-action], button[data-nextphp-action]');
            if (!btn || btn.type === 'submit') return;

            const actionName = btn.dataset.nexusAction || btn.dataset.nextphpAction;
            if (!actionName) return;

            e.preventDefault();
            setButtonLoading(btn, true);

            let payload = {};
            if (btn.dataset.payload) {
                try {
                    payload = JSON.parse(btn.dataset.payload);
                } catch (err) {
                    payload = {};
                }
            }

            try {
                await runServerAction(actionName, payload);
            } finally {
                setButtonLoading(btn, false);
            }
        });

        // 5. Handle History Navigation (Back / Forward)
        window.addEventListener('popstate', () => {
            navigateTo(window.location.pathname + window.location.search, false);
        });
    });

    // Expose Global APIs
    window.NexusSPA = {
        navigate: navigateTo,
        prefetch: prefetch,
        runAction: runServerAction,
        toast: NexusToast,
    };

    window.runServerAction = runServerAction;
    window.runNexusAction = runServerAction;
    window.NexusToast = NexusToast;

})();
