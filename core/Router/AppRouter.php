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
 * - Zero-AJAX pushState SPA morphing via Alpine Morph (X-NextPHP-SPA: true)
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
            'X-NextPHP-SPA'    => 'true',
            'X-NextPHP-Morph'  => 'true',
            'X-Nexus-Morph'    => 'true',
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
     * Render Next.js-inspired 404 page.
     */
    protected function renderNotFound(Request $request): Response
    {
        $custom404 = $this->pagesDirectory . '/404.php';
        if (file_exists($custom404)) {
            $rootLayout = $this->pagesDirectory . '/layout.php';
            $layouts = file_exists($rootLayout) ? [$rootLayout] : [];
            return $this->renderView($custom404, $layouts, $request, []);
        }

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
            color: #f9fafb;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
        .container {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .code {
            font-size: 2rem;
            font-weight: 700;
            border-right: 1px solid #374151;
            padding-right: 1.5rem;
            color: #ef4444;
        }
        .msg {
            font-size: 1rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="code">404</div>
        <div class="msg">This page could not be found.</div>
    </div>
</body>
</html>
HTML;
        return Response::html($html, 404);
    }
}
