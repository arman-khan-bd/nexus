<?php

declare(strict_types=1);

namespace Core;

use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AntiDdos;
use Core\Security\Firewall;
use Core\Security\SessionGuard;
use Core\Security\Csrf;
use Core\Router\AppRouter;
use Throwable;
use ErrorException;

/**
 * Class Kernel
 *
 * Nexus Application Security Kernel.
 * Orchestrates error handling, defensive WAF shielding, rate limiting,
 * hardened session lifecycle, and Next.js-style file-system routing.
 */
final class Kernel
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Boot the Nexus application kernel.
     */
    public static function boot(string $basePath): self
    {
        $kernel = new self($basePath);
        $kernel->registerErrorHandlers();
        return $kernel;
    }

    /**
     * Handle incoming HTTP request and emit Response.
     */
    public function handle(): void
    {
        try {
            $request = Request::capture();

            // 1. Lightweight cPanel Anti-DDoS, Bot Mitigation & Sliding Window Shield
            $ddosBlock = AntiDdos::inspect($request);
            if ($ddosBlock instanceof Response) {
                $ddosBlock->send();
                return;
            }

            // 2. Deep Input Sanity & Application Firewall (OWASP Top 10)
            $firewallBlock = Firewall::inspect($request);
            if ($firewallBlock instanceof Response) {
                $firewallBlock->send();
                return;
            }

            // 3. Security Hardened Session & Subnet Anti-Hijacking
            SessionGuard::start($request);

            // 4. Session-Bound Cryptographic CSRF Defense (Mutating HTTP Verbs)
            $csrfBlock = Csrf::validateRequest($request);
            if ($csrfBlock instanceof Response) {
                $csrfBlock->send();
                return;
            }

            // 5. App Router (Next.js App Router convention & Alpine Morph SPA)
            $router = new AppRouter($this->basePath . '/pages');
            $response = $router->dispatch($request);

            // 6. Transmit Response
            $response->send();
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Register strict error & exception handlers.
     */
    private function registerErrorHandlers(): void
    {
        error_reporting(E_ALL);

        set_error_handler(function (int $severity, string $message, string $file, int $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e) {
            $this->handleException($e);
        });
    }

    /**
     * Exception presentation: Rich debugger in dev, hardened fortress in production.
     */
    private function handleException(Throwable $e): void
    {
        $incidentId = 'nex_' . substr(bin2hex(random_bytes(6)), 0, 10);

        // 1. Log exception to storage/logs
        $logDir = $this->basePath . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }
        $logEntry = sprintf(
            "[%s] [%s] [EXCEPTION] %s: %s in %s:%d\nStack Trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            $incidentId,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        @file_put_contents($logDir . '/nexus_error.log', $logEntry, FILE_APPEND | LOCK_EX);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Nexus-Incident: ' . $incidentId);
        }

        $request = Request::capture();

        // 2. Handle JSON / AJAX / SPA requests
        if ($request->isJson() || $request->hasHeader('x-nexus-action') || $request->hasHeader('x-nextphp-action')) {
            echo json_encode([
                'success'     => false,
                'error'       => 'Server Error',
                'incident_id' => $incidentId,
                'message'     => Env::isDebug() ? $e->getMessage() : 'An unexpected server error occurred.',
                'file'        => Env::isDebug() ? $e->getFile() : null,
                'line'        => Env::isDebug() ? $e->getLine() : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        // 3. Render HTML UI
        if (Env::isDebug()) {
            $this->renderDebugException($e, $incidentId, $request);
        } else {
            $this->renderProductionError($incidentId);
        }
    }

    /**
     * Read code context around the line that threw the exception.
     *
     * @return array<int, array{line: int, code: string, is_error: bool}>
     */
    private function getCodeSnippet(string $filePath, int $errorLine, int $padding = 5): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        $lines = @file($filePath);
        if ($lines === false || empty($lines)) {
            return [];
        }

        $start = max(1, $errorLine - $padding);
        $end = min(count($lines), $errorLine + $padding);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $snippet[$i] = [
                'line'     => $i,
                'code'     => rtrim($lines[$i - 1], "\r\n"),
                'is_error' => ($i === $errorLine),
            ];
        }

        return $snippet;
    }

    /**
     * Developer debug error view with stack trace, code snippet, and Copy Error button.
     */
    private function renderDebugException(Throwable $e, string $incidentId, ?Request $request = null): void
    {
        $class = get_class($e);
        $message = htmlspecialchars($e->getMessage() ?: '(No message provided)', ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $traceString = $e->getTraceAsString();
        $trace = htmlspecialchars($traceString, ENT_QUOTES, 'UTF-8');

        $reqMethod = $request ? htmlspecialchars($request->getMethod(), ENT_QUOTES, 'UTF-8') : 'CLI/GET';
        $reqUri = $request ? htmlspecialchars($request->getUri(), ENT_QUOTES, 'UTF-8') : '/';
        $clientIp = $request ? htmlspecialchars($request->getIp(), ENT_QUOTES, 'UTF-8') : '127.0.0.1';
        $timestamp = date('Y-m-d H:i:s T');
        $phpVersion = PHP_VERSION;
        $appEnv = Env::string('APP_ENV', 'local');

        // Extract code snippet around offending line
        $snippet = $this->getCodeSnippet($e->getFile(), $line, 6);
        $snippetHtml = '';
        $rawSnippetText = '';

        if (!empty($snippet)) {
            foreach ($snippet as $item) {
                $lineNum = $item['line'];
                $codeLine = htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8');
                $isErr = $item['is_error'];
                $errClass = $isErr ? 'line-error' : '';
                $marker = $isErr ? '<span class="marker">&gt;</span>' : '<span class="marker">&nbsp;</span>';

                $snippetHtml .= "<div class=\"code-row {$errClass}\">";
                $snippetHtml .= "<span class=\"line-num\">{$lineNum}</span>";
                $snippetHtml .= "{$marker}";
                $snippetHtml .= "<span class=\"line-code\">{$codeLine}</span>";
                $snippetHtml .= "</div>";

                $rawSnippetText .= sprintf("%5d %s %s\n", $lineNum, $isErr ? '>' : ' ', $item['code']);
            }
        } else {
            $snippetHtml = '<div class="p-4 text-slate-500 italic">Source file could not be read or is outside the project workspace.</div>';
        }

        // Build Markdown payload for Copy Error button
        $rawPayload = "### Nexus Error Report\n";
        $rawPayload .= "- **Incident ID**: `{$incidentId}`\n";
        $rawPayload .= "- **Exception**: `{$class}`\n";
        $rawPayload .= "- **Message**: {$e->getMessage()}\n";
        $rawPayload .= "- **Location**: `{$e->getFile()}:{$line}`\n";
        $rawPayload .= "- **Request**: `{$reqMethod} {$reqUri}`\n";
        $rawPayload .= "- **Client IP**: `{$clientIp}`\n";
        $rawPayload .= "- **PHP Version**: `{$phpVersion}`\n";
        $rawPayload .= "- **Environment**: `{$appEnv}` (Debug: active)\n";
        $rawPayload .= "- **Timestamp**: {$timestamp}\n\n";
        if (!empty($rawSnippetText)) {
            $rawPayload .= "#### Code Context:\n```php\n" . $rawSnippetText . "```\n\n";
        }
        $rawPayload .= "#### Stack Trace:\n```\n" . $traceString . "\n```";

        $escapedPayload = htmlspecialchars($rawPayload, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>[{$class}] {$message} - Nexus Error</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: #030712;
            color: #f3f4f6;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            padding: 1.5rem 1rem 3rem;
            line-height: 1.5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            background: #0b1120;
            border: 1px solid #1e293b;
            border-radius: 12px;
            padding: 0.85rem 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .brand {
            font-weight: 800;
            font-size: 0.95rem;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .brand span {
            color: #38bdf8;
        }
        .badge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .badge-muted {
            background: #1e293b;
            color: #94a3b8;
            border: 1px solid #334155;
        }
        .badge-info {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .copy-btn {
            font-family: ui-sans-serif, system-ui, sans-serif;
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
        .icon-btn {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: pointer;
            transition: background 0.15s;
        }
        .icon-btn:hover {
            background: #334155;
            color: #ffffff;
        }
        .card-main {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-left: 5px solid #ef4444;
            border-radius: 12px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
        }
        .error-type {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.82rem;
            color: #f87171;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.05em;
            margin-bottom: 0.4rem;
        }
        .error-msg {
            font-size: 1.45rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.75rem;
            word-break: break-word;
            line-height: 1.35;
        }
        .error-loc {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.88rem;
            color: #94a3b8;
            background: #030712;
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .error-loc strong {
            color: #f1f5f9;
        }
        .error-loc .line-badge {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            padding: 0.15rem 0.5rem;
            border-radius: 5px;
            font-weight: 700;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .tabs {
            display: flex;
            gap: 0.5rem;
            border-bottom: 1px solid #1e293b;
            margin-bottom: 1rem;
            padding-bottom: 0.25rem;
        }
        .tab-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.5rem 1rem;
            cursor: pointer;
            border-radius: 8px 8px 0 0;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .tab-btn:hover {
            color: #ffffff;
            background: #1e293b;
        }
        .tab-btn.active {
            color: #38bdf8;
            background: #0f172a;
            border: 1px solid #1e293b;
            border-bottom-color: #0f172a;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .snippet-box {
            background: #030712;
            border: 1px solid #1e293b;
            border-radius: 10px;
            overflow: hidden;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.82rem;
            line-height: 1.6;
        }
        .snippet-header {
            background: #0b1120;
            padding: 0.5rem 0.85rem;
            border-bottom: 1px solid #1e293b;
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .code-row {
            display: flex;
            align-items: center;
            padding: 0.15rem 0.5rem;
        }
        .code-row:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .code-row.line-error {
            background: rgba(239, 68, 68, 0.18) !important;
            border-left: 3px solid #ef4444;
        }
        .line-num {
            width: 3.5rem;
            color: #475569;
            text-align: right;
            padding-right: 1rem;
            user-select: none;
            flex-shrink: 0;
        }
        .line-error .line-num {
            color: #f87171;
            font-weight: 700;
        }
        .marker {
            width: 1.25rem;
            color: #ef4444;
            font-weight: 800;
            flex-shrink: 0;
        }
        .line-code {
            color: #e2e8f0;
            white-space: pre;
            overflow-x: auto;
            flex-grow: 1;
        }
        .line-error .line-code {
            color: #ffffff;
            font-weight: 600;
        }
        .trace-box {
            background: #030712;
            border: 1px solid #1e293b;
            border-radius: 10px;
            padding: 1.25rem;
            overflow-x: auto;
            color: #cbd5e1;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.82rem;
            line-height: 1.7;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .grid-props {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }
        .prop-card {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 10px;
            padding: 1rem;
        }
        .prop-card-title {
            color: #94a3b8;
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 0.35rem;
            letter-spacing: 0.05em;
        }
        .prop-card-value {
            color: #f1f5f9;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.85rem;
            word-break: break-all;
        }
        .hidden {
            display: none !important;
        }
        .icon {
            width: 1rem;
            height: 1rem;
            display: inline-block;
            vertical-align: middle;
        }
        .clickable-copy {
            cursor: pointer;
            transition: all 0.15s ease;
            position: relative;
        }
        .clickable-copy:hover {
            color: #38bdf8 !important;
        }
        .error-msg.clickable-copy:hover {
            text-decoration: underline dotted #38bdf8;
        }
        .error-loc.clickable-copy:hover {
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
        .trace-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        .trace-header-title {
            color: #94a3b8;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Topbar with Right-Side Copy Error Button -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="brand">nexus<span>.php</span> v2.0</div>
                <span class="badge badge-danger">500 Server Error</span>
                <span class="badge badge-info clickable-copy" onclick="copySnippetText('{$incidentId}', 'Copied Incident Reference #{$incidentId}!')" title="Click to copy Incident ID">Incident #{$incidentId}</span>
                <span class="badge badge-muted">Env: {$appEnv}</span>
            </div>
            <div class="topbar-right">
                <button id="copy-error-btn" class="copy-btn" onclick="copyErrorDetails()" title="Copy formatted error report to clipboard">
                    <svg id="copy-icon" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    <svg id="check-icon" class="icon hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span id="copy-btn-text">Copy Error</span>
                </button>
                <button class="icon-btn" onclick="window.location.reload()">
                    <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Reload
                </button>
                <a href="/" class="icon-btn">
                    <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    Home
                </a>
            </div>
        </div>

        <!-- Main Error Card with Click-to-Copy -->
        <div class="card-main">
            <div class="error-type">{$class}</div>
            <h1 class="error-msg clickable-copy" onclick="copySnippetText(this.innerText, 'Copied exception message!')" title="Click to copy error message">{$message}</h1>
            <div class="error-loc clickable-copy" onclick="copySnippetText('{$file}:{$line}', 'Copied file path and line number!')" title="Click to copy source location">
                <div>Source: <strong>{$file}</strong></div>
                <div class="line-badge">Line {$line}</div>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs">
            <button id="btn-tab-snippet" class="tab-btn active" onclick="switchTab('tab-snippet')">
                <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                </svg>
                Code Snippet
            </button>
            <button id="btn-tab-trace" class="tab-btn" onclick="switchTab('tab-trace')">
                <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
                Stack Trace
            </button>
            <button id="btn-tab-request" class="tab-btn" onclick="switchTab('tab-request')">
                <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                </svg>
                Request Context
            </button>
            <button id="btn-tab-env" class="tab-btn" onclick="switchTab('tab-env')">
                <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Environment
            </button>
        </div>

        <!-- Tab 1: Code Context -->
        <div id="tab-snippet" class="tab-content active">
            <div class="snippet-box">
                <div class="snippet-header">
                    <span>{$file} (around line {$line})</span>
                    <button class="icon-btn" onclick="copySnippetText('{$file}:{$line}', 'Copied file path!')" style="padding:0.2rem 0.5rem; font-size:0.75rem;">Copy Path</button>
                </div>
                {$snippetHtml}
            </div>
        </div>

        <!-- Tab 2: Stack Trace -->
        <div id="tab-trace" class="tab-content">
            <div class="trace-header">
                <span class="trace-header-title">Exception Stack Trace</span>
                <button class="icon-btn" onclick="copySnippetText(document.getElementById('raw-trace-content').value, 'Copied full stack trace!')">
                    <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    Copy Trace
                </button>
            </div>
            <div class="trace-box">{$trace}</div>
            <textarea id="raw-trace-content" class="hidden">{$trace}</textarea>
        </div>

        <!-- Tab 3: Request Context -->
        <div id="tab-request" class="tab-content">
            <div class="grid-props">
                <div class="prop-card">
                    <div class="prop-card-title">HTTP Method</div>
                    <div class="prop-card-value">{$reqMethod}</div>
                </div>
                <div class="prop-card">
                    <div class="prop-card-title">Request URI</div>
                    <div class="prop-card-value">{$reqUri}</div>
                </div>
                <div class="prop-card">
                    <div class="prop-card-title">Client IP</div>
                    <div class="prop-card-value">{$clientIp}</div>
                </div>
                <div class="prop-card">
                    <div class="prop-card-title">Timestamp</div>
                    <div class="prop-card-value">{$timestamp}</div>
                </div>
            </div>
        </div>

        <!-- Tab 4: Environment -->
        <div id="tab-env" class="tab-content">
            <div class="grid-props">
                <div class="prop-card">
                    <div class="prop-card-title">PHP Version</div>
                    <div class="prop-card-value">{$phpVersion} (" . PHP_SAPI . ")</div>
                </div>
                <div class="prop-card">
                    <div class="prop-card-title">Nexus Environment</div>
                    <div class="prop-card-value">{$appEnv} (Debug: true)</div>
                </div>
                <div class="prop-card">
                    <div class="prop-card-title">Libsodium Status</div>
                    <div class="prop-card-value">" . (extension_loaded('sodium') ? '<span style="color:#34d399">Enabled (Native)</span>' : '<span style="color:#fbbf24">OpenSSL Fallback Active</span>') . "</div>
                </div>
                <div class="prop-card">
                    <div class="prop-card-title">Peak Memory Usage</div>
                    <div class="prop-card-value">" . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Raw Payload for Copy Button -->
    <textarea id="raw-error-payload" class="hidden">{$escapedPayload}</textarea>

    <!-- Floating Copy Toast -->
    <div id="nexus-copy-toast" class="copy-toast"></div>

    <script>
        function showCopyToast(msg) {
            let toast = document.getElementById('nexus-copy-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'nexus-copy-toast';
                toast.className = 'copy-toast';
                document.body.appendChild(toast);
            }
            toast.innerHTML = '<svg class="icon" style="color:#34d399; margin-right:6px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg><span>' + msg + '</span>';
            toast.classList.add('visible');
            clearTimeout(window.__toastTimer);
            window.__toastTimer = setTimeout(() => {
                toast.classList.remove('visible');
            }, 2500);
        }

        function copySnippetText(text, successMsg) {
            const onSuccess = () => showCopyToast(successMsg || 'Copied to clipboard!');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(onSuccess).catch(() => fallbackCopy(text, onSuccess));
            } else {
                fallbackCopy(text, onSuccess);
            }
        }

        function copyErrorDetails() {
            const rawData = document.getElementById('raw-error-payload').value;
            const btn = document.getElementById('copy-error-btn');
            const textSpan = document.getElementById('copy-btn-text');
            const copyIcon = document.getElementById('copy-icon');
            const checkIcon = document.getElementById('check-icon');

            const onSuccess = () => {
                btn.classList.add('copied');
                if (textSpan) textSpan.innerText = 'Copied to Clipboard!';
                if (copyIcon) copyIcon.classList.add('hidden');
                if (checkIcon) checkIcon.classList.remove('hidden');
                showCopyToast('Full structured error report copied to clipboard!');

                setTimeout(() => {
                    btn.classList.remove('copied');
                    if (textSpan) textSpan.innerText = 'Copy Error';
                    if (copyIcon) copyIcon.classList.remove('hidden');
                    if (checkIcon) checkIcon.classList.add('hidden');
                }, 2500);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(rawData).then(onSuccess).catch(() => fallbackCopy(rawData, onSuccess));
            } else {
                fallbackCopy(rawData, onSuccess);
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
                alert('Could not copy automatically. Please copy the error manually.');
            }
            document.body.removeChild(textarea);
        }

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

            const target = document.getElementById(tabId);
            const btn = document.getElementById('btn-' + tabId);
            if (target) target.classList.add('active');
            if (btn) btn.classList.add('active');
        }
    </script>
</body>
</html>
HTML;
    }

    /**
     * Hardened production error page with right-side Copy Incident ID button.
     */
    private function renderProductionError(string $incidentId): void
    {
        $timestamp = date('Y-m-d H:i:s T');
        $rawIncident = "Nexus Incident ID: {$incidentId}\nTimestamp: {$timestamp}\nStatus: 500 Server Error";
        $escapedIncident = htmlspecialchars($rawIncident, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - Internal Server Error | Nexus</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #030712;
            color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .box {
            border: 1px solid #1e293b;
            background: #0f172a;
            padding: 2.5rem;
            border-radius: 16px;
            max-width: 520px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }
        .box-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            border-bottom: 1px solid #1e293b;
            padding-bottom: 1rem;
        }
        .badge {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-family: monospace;
        }
        .copy-btn {
            font-size: 0.78rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: #1e293b;
            color: #f8fafc;
            border: 1px solid #3b82f6;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .copy-btn:hover {
            background: #2563eb;
            color: #ffffff;
        }
        .copy-btn.copied {
            background: rgba(16, 185, 129, 0.2) !important;
            border-color: #10b981 !important;
            color: #34d399 !important;
        }
        h1 {
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        p {
            color: #94a3b8;
            font-size: 0.92rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        .incident-card {
            background: #030712;
            border: 1px solid #1e293b;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.85rem;
            color: #38bdf8;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .incident-card:hover {
            border-color: #38bdf8;
            background: #0b1120;
        }
        .actions {
            display: flex;
            gap: 0.75rem;
        }
        .btn-primary {
            flex: 1;
            background: #2563eb;
            color: #ffffff;
            text-align: center;
            padding: 0.7rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 600;
            transition: background 0.15s;
        }
        .btn-primary:hover {
            background: #1d4ed8;
        }
        .btn-secondary {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 0.7rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-secondary:hover {
            background: #334155;
            color: #ffffff;
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
        .hidden { display: none !important; }
        .icon { width: 1rem; height: 1rem; display: inline-block; vertical-align: middle; }
    </style>
</head>
<body>
    <div class="box">
        <div class="box-header">
            <span class="badge">HTTP 500</span>
            <button id="prod-copy-btn" class="copy-btn" onclick="copyProdIncident()" title="Copy incident reference to clipboard">
                <svg id="prod-copy-icon" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                <svg id="prod-check-icon" class="icon hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span id="prod-btn-text">Copy Incident ID</span>
            </button>
        </div>
        <h1>Something went wrong</h1>
        <p>An unexpected server error occurred while processing your request. Our telemetry system has logged this incident.</p>
        
        <div class="incident-card" onclick="copyProdIncident()" title="Click to copy Incident Reference">
            <span>Incident Reference:</span>
            <strong>#{$incidentId}</strong>
        </div>

        <div class="actions">
            <a href="/" class="btn-primary">Return Home</a>
            <button class="btn-secondary" onclick="window.location.reload()">Retry</button>
        </div>
    </div>

    <textarea id="raw-prod-incident" class="hidden">{$escapedIncident}</textarea>
    <div id="nexus-prod-toast" class="copy-toast"></div>

    <script>
        function showProdToast(msg) {
            let toast = document.getElementById('nexus-prod-toast');
            if (toast) {
                toast.innerHTML = '<svg class="icon" style="color:#34d399; margin-right:6px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg><span>' + msg + '</span>';
                toast.classList.add('visible');
                clearTimeout(window.__prodToastTimer);
                window.__prodToastTimer = setTimeout(() => {
                    toast.classList.remove('visible');
                }, 2500);
            }
        }

        function copyProdIncident() {
            const raw = document.getElementById('raw-prod-incident').value;
            const btn = document.getElementById('prod-copy-btn');
            const txt = document.getElementById('prod-btn-text');
            const copyIcon = document.getElementById('prod-copy-icon');
            const checkIcon = document.getElementById('prod-check-icon');

            const onSuccess = () => {
                btn.classList.add('copied');
                if (txt) txt.innerText = 'Copied!';
                if (copyIcon) copyIcon.classList.add('hidden');
                if (checkIcon) checkIcon.classList.remove('hidden');
                showProdToast('Incident reference copied to clipboard!');

                setTimeout(() => {
                    btn.classList.remove('copied');
                    if (txt) txt.innerText = 'Copy Incident ID';
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
                alert('Could not copy incident automatically.');
            }
            document.body.removeChild(textarea);
        }
    </script>
</body>
</html>
HTML;
    }
}
