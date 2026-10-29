<?php

declare(strict_types=1);

namespace Core\Router;

use Core\Http\Request;
use Core\Http\Response;
use Core\Security\Csrf;
use Core\Security\SessionGuard;
use Core\Compiler\JsxCompiler;

/**
 * Class AppRouter
 *
 * Next.js App Router File-System Engine for Nexus.
 *
 * Key Capabilities:
 * - Dynamic route parameters: /dashboard/[id]/page.php, /docs/[...slug]/page.php
 * - Automatic hierarchical nested layouts (root to leaf layout.php discovery)
 * - Zero-AJAX pushState SPA morphing via Alpine Morph (X-Nexus-SPA: true)
 * - Unified Server Action execution pipeline
 * - Graceful 404 fallback with custom pages/404.php support
 */
class AppRouter
{
    protected string $pagesDirectory;

    public function __construct(?string $pagesDirectory = null)
    {
        $this->pagesDirectory = $pagesDirectory ?? (dirname(__DIR__, 2) . '/pages');
    }

    /**
     * Dispatch HTTP request to the matching page, layout hierarchy, or server action.
     */
    public function dispatch(Request $request): Response
    {
        $path = $request->getPath();

        // 1. Resolve matching route and nested layouts
        $match = $this->resolveRoute($path);

        if ($match !== null) {
            $request->setRouteParams($match['params']);
        }

        // 2. Check if request is a Server Action
        if (ServerAction::isAction($request)) {
            $pageFile = $match['pageFile'] ?? null;
            $actionResponse = ServerAction::handle($request, $pageFile);
            if ($actionResponse instanceof Response) {
                return $actionResponse;
            }
        }

        // 3. If no route matched, return 404
        if ($match === null) {
            return $this->renderNotFound($request);
        }

        // 4. Render page with layout nesting and SPA awareness
        return $this->renderView($match['pageFile'], $match['layouts'], $request, $match['params']);
    }

    /**
     * Matches the URL path against file system pages directory.
     *
     * @param string $path
     * @return array{pageFile: string, layouts: array<string>, params: array<string, string>}|null
     */
    public function resolveRoute(string $path): ?array
    {
        $cleanPath = trim($path, '/');
        $segments = $cleanPath === '' ? [] : explode('/', $cleanPath);

        return $this->findMatchingPage($this->pagesDirectory, $segments, [], []);
    }

    /**
     * Recursively traverses directories to match static and [parameter] folders.
     */
    protected function findMatchingPage(string $currentDir, array $segments, array $params, array $layouts): ?array
    {
        // Collect layout.php if present at this directory level
        $layoutFile = $currentDir . '/layout.php';
        if (file_exists($layoutFile)) {
            $layouts[] = $layoutFile;
        }

        // Base case: all URL segments matched
        if (empty($segments)) {
            $pageFile = $currentDir . '/page.php';
            if (file_exists($pageFile)) {
                return [
                    'pageFile' => $pageFile,
                    'layouts'  => $layouts,
                    'params'   => $params,
                ];
            }
            return null;
        }

        $segment = array_shift($segments);

        // 1. Exact static directory match (e.g. /dashboard/settings)
        $staticDir = $currentDir . '/' . $segment;
        if (is_dir($staticDir)) {
            $result = $this->findMatchingPage($staticDir, $segments, $params, $layouts);
            if ($result !== null) {
                return $result;
            }
        }

        // 2. Dynamic directory match: [param] or [...catchAll]
        if (is_dir($currentDir)) {
            $entries = scandir($currentDir);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    $candidatePath = $currentDir . '/' . $entry;
                    if (is_dir($candidatePath) && str_starts_with($entry, '[') && str_ends_with($entry, ']')) {
                        $paramKey = substr($entry, 1, -1);

                        // Catch-all route: [...slug]
                        if (str_starts_with($paramKey, '...')) {
                            $varName = substr($paramKey, 3);
                            $allRemaining = array_merge([$segment], $segments);
                            $newParams = array_merge($params, [$varName => implode('/', $allRemaining)]);
                            $pageFile = $candidatePath . '/page.php';
                            if (file_exists($pageFile)) {
                                $candidateLayout = $candidatePath . '/layout.php';
                                if (file_exists($candidateLayout)) {
                                    $layouts[] = $candidateLayout;
                                }
                                return [
                                    'pageFile' => $pageFile,
                                    'layouts'  => $layouts,
                                    'params'   => $newParams,
                                ];
                            }
                        }

                        // Single param route: [id]
                        $newParams = array_merge($params, [$paramKey => $segment]);
                        $result = $this->findMatchingPage($candidatePath, $segments, $newParams, $layouts);
                        if ($result !== null) {
                            return $result;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Renders page and progressively wraps through hierarchical layouts.
     * Supports zero-reload DOM morphing when X-NextPHP-SPA is detected.
     */
    protected function renderView(string $pageFile, array $layouts, Request $request, array $params): Response
    {
        $csrfToken = Csrf::token();

        // 1. Prepare render context
        $renderContext = [
            'request'   => $request,
            'params'    => $params,
            'csrfToken' => $csrfToken,
        ];

        // 2. Render innermost Page
        $pageContent = $this->renderIsolated($pageFile, $renderContext);

        // 3. SPA Request Optimization (X-NextPHP-SPA or X-Nexus-SPA)
        if ($request->isSpa()) {
            return $this->renderSpaMorph($pageContent, $layouts, $renderContext);
        }

        // 4. Standard Browser Request: Wrap through all layouts (leaf to root)
        $children = $pageContent;
        $reversedLayouts = array_reverse($layouts);

        foreach ($reversedLayouts as $layoutFile) {
            $layoutContext = array_merge($renderContext, [
                'children' => $children,
            ]);
            $children = $this->renderIsolated($layoutFile, $layoutContext);
        }

        return Response::html($children);
    }

    /**
     * Render optimized partial for Alpine Morph SPA DOM updates.
     */
    protected function renderSpaMorph(string $pageContent, array $layouts, array $renderContext): Response
    {
        // Exclude root layout (index 0) if multiple layouts exist, since the root shell is persistent
        $subLayouts = count($layouts) > 1 ? array_slice($layouts, 1) : [];

        $children = $pageContent;
        $reversedSubLayouts = array_reverse($subLayouts);

        foreach ($reversedSubLayouts as $layoutFile) {
            $layoutContext = array_merge($renderContext, [
                'children' => $children,
            ]);
            $children = $this->renderIsolated($layoutFile, $layoutContext);
        }

        // Ensure content is wrapped in the #nexus-root container element
        $output = $children;
        if (!str_contains($output, 'id="nexus-root"')) {
            $output = '<main id="nexus-root" class="flex-1 max-w-6xl mx-auto px-6 py-10 w-full">' . $children . '</main>';
        }

        // Extract title if present in page or layout
        $title = 'Nexus Framework';
        if (preg_match('/<title>(.*?)<\/title>/is', $pageContent, $titleMatch)) {
            $title = trim($titleMatch[1]);
        }

        return new Response($output, 200, [
            'Content-Type'     => 'text/html; charset=UTF-8',
            'X-Nexus-SPA'      => 'true',
            'X-NextPHP-SPA'    => 'true',
            'X-Nexus-Morph'    => 'true',
            'X-NextPHP-Morph'  => 'true',
            'X-Nexus-Title'    => $title,
            'X-NextPHP-Title'  => $title,
            'X-CSRF-TOKEN'     => Csrf::token(),
        ]);
    }

    /**
     * Isolates variable scope and compiles JSX when evaluating PHP template files.
     */
    protected function renderIsolated(string $filePath, array $context): string
    {
        return JsxCompiler::render($filePath, $context);
    }

    /**
     * Render Next.js-inspired 404 page with right-side Copy Error button.
     */
    protected function renderNotFound(Request $request): Response
    {
        $custom404 = $this->pagesDirectory . '/404.php';
        if (file_exists($custom404)) {
            $rootLayout = $this->pagesDirectory . '/layout.php';
            $layouts = file_exists($rootLayout) ? [$rootLayout] : [];
            return $this->renderView($custom404, $layouts, $request, []);
        }

        $uri = htmlspecialchars($request->getPath() ?: $request->getRawUri(), ENT_QUOTES, 'UTF-8');
        $method = htmlspecialchars($request->getMethod(), ENT_QUOTES, 'UTF-8');
        $ip = htmlspecialchars($request->getIp(), ENT_QUOTES, 'UTF-8');
        $timestamp = date('Y-m-d H:i:s T');
        $incidentId = 'nex_' . substr(bin2hex(random_bytes(6)), 0, 10);

        $rawUri = $request->getPath() ?: $request->getRawUri();
        $rawErrorText = "### Nexus 404 Route Not Found\n";
        $rawErrorText .= "- **Status**: 404 Not Found\n";
        $rawErrorText .= "- **Incident ID**: `{$incidentId}`\n";
        $rawErrorText .= "- **Requested URI**: `{$rawUri}`\n";
        $rawErrorText .= "- **HTTP Method**: {$request->getMethod()}\n";
        $rawErrorText .= "- **Client IP**: {$request->getIp()}\n";
        $rawErrorText .= "- **Timestamp**: {$timestamp}\n";
        $escapedErrorPayload = htmlspecialchars($rawErrorText, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | Nexus</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: #030712;
            color: #f1f5f9;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
            line-height: 1.5;
        }
        .card {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 16px;
            max-width: 540px;
            width: 100%;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #1e293b;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .brand-badge {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 0.75rem;
            font-weight: 700;
            background: #1e293b;
            color: #94a3b8;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            border: 1px solid #334155;
        }
        .brand-badge span { color: #38bdf8; }
        .status-badge {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 0.75rem;
            font-weight: 700;
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .copy-btn {
            font-family: inherit;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            background: #1e293b;
            color: #f8fafc;
            border: 1px solid #3b82f6;
            padding: 0.45rem 0.85rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .copy-btn:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #60a5fa;
            transform: translateY(-1px);
        }
        .copy-btn.copied {
            background: rgba(16, 185, 129, 0.2) !important;
            border-color: #10b981 !important;
            color: #34d399 !important;
        }
        .icon {
            width: 1rem;
            height: 1rem;
            display: inline-block;
            vertical-align: middle;
        }
        .hero {
            display: flex;
            align-items: baseline;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }
        .code-display {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1;
            color: #ef4444;
            font-family: ui-monospace, SFMono-Regular, monospace;
        }
        .title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #ffffff;
        }
        .message {
            color: #94a3b8;
            font-size: 0.95rem;
            margin-bottom: 1.25rem;
            line-height: 1.6;
        }
        .route-box {
            background: #030712;
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .route-box:hover {
            border-color: #38bdf8;
            background: #0b1120;
        }
        .copy-toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: #0f172a;
            color: #f8fafc;
            border: 1px solid #10b981;
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.6);
            transform: translateY(80px);
            opacity: 0;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 9999;
            pointer-events: none;
        }
        .copy-toast.visible {
            transform: translateY(0);
            opacity: 1;
        }
        .method-tag {
            color: #38bdf8;
            font-weight: 700;
            margin-right: 0.5rem;
        }
        .uri-text {
            color: #e2e8f0;
            word-break: break-all;
        }
        .hint-box {
            font-size: 0.8rem;
            color: #64748b;
            background: rgba(30, 41, 59, 0.5);
            border-left: 3px solid #3b82f6;
            padding: 0.6rem 0.85rem;
            border-radius: 0 6px 6px 0;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }
        .hint-box code {
            color: #93c5fd;
            font-family: ui-monospace, monospace;
        }
        .actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .btn-primary {
            flex: 1;
            min-width: 140px;
            background: #2563eb;
            color: #ffffff;
            text-align: center;
            padding: 0.65rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 600;
            transition: background 0.15s;
        }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 0.65rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            cursor: pointer;
            font-size: 0.88rem;
            font-weight: 600;
            transition: background 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-secondary:hover { background: #334155; color: #ffffff; }
        .hidden { display: none !important; }
    </style>
</head>
<body>
    <div class="card">
        <!-- Top Header with Right-Side Copy Error Button -->
        <div class="card-header">
            <div class="header-left">
                <span class="brand-badge">nexus<span>.php</span></span>
                <span class="status-badge">404 Not Found</span>
            </div>
            <!-- Right-Side Copy Error Button -->
            <button id="copy-404-btn" class="copy-btn" onclick="copyErrorDetails()" title="Copy route error details to clipboard">
                <svg id="copy-icon" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                <svg id="check-icon" class="icon hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span id="copy-btn-txt">Copy Error</span>
            </button>
        </div>

        <div class="hero">
            <div class="code-display">404</div>
            <div class="title">Route Not Found</div>
        </div>

        <p class="message">The requested page or endpoint does not exist or has been relocated.</p>

        <div class="route-box" onclick="copyErrorDetails()" title="Click to copy route error details">
            <div>
                <span class="method-tag">{$method}</span>
                <span class="uri-text">{$uri}</span>
            </div>
            <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Click to copy</span>
        </div>

        <div class="hint-box">
            Developer Hint: Define this route by creating <code>pages{$uri}/page.php</code> or configuring a custom route handler.
        </div>

        <div class="actions">
            <a href="/" class="btn-primary">Return Home</a>
            <a href="/docs" class="btn-secondary">Documentation</a>
            <button class="btn-secondary" onclick="window.location.reload()">Reload</button>
        </div>
    </div>

    <textarea id="raw-404-payload" class="hidden">{$escapedErrorPayload}</textarea>
    <div id="nexus-404-toast" class="copy-toast"></div>

    <script>
        function show404Toast(msg) {
            let toast = document.getElementById('nexus-404-toast');
            if (toast) {
                toast.innerHTML = '<svg class="icon" style="color:#34d399; margin-right:6px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg><span>' + msg + '</span>';
                toast.classList.add('visible');
                clearTimeout(window.__toast404Timer);
                window.__toast404Timer = setTimeout(() => {
                    toast.classList.remove('visible');
                }, 2500);
            }
        }

        function copyErrorDetails() {
            const raw = document.getElementById('raw-404-payload').value;
            const btn = document.getElementById('copy-404-btn');
            const txt = document.getElementById('copy-btn-txt');
            const copyIcon = document.getElementById('copy-icon');
            const checkIcon = document.getElementById('check-icon');

            const onSuccess = () => {
                btn.classList.add('copied');
                if (txt) txt.innerText = 'Copied to Clipboard!';
                if (copyIcon) copyIcon.classList.add('hidden');
                if (checkIcon) checkIcon.classList.remove('hidden');
                show404Toast('Route details copied to clipboard!');

                setTimeout(() => {
                    btn.classList.remove('copied');
                    if (txt) txt.innerText = 'Copy Error';
                    if (copyIcon) copyIcon.classList.remove('hidden');
                    if (checkIcon) checkIcon.classList.add('hidden');
                }, 2500);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(raw).then(onSuccess).catch(() => fallbackCopy(raw, onSuccess));
            } else {
                fallbackCopy(raw, onSuccess);
            }
        }

        function fallbackCopy(text, callback) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                callback();
            } catch (e) {
                alert('Could not copy automatically. Please copy the route manually.');
            }
            document.body.removeChild(textarea);
        }
    </script>
</body>
</html>
HTML;
        return Response::html($html, 404);
    }
}
