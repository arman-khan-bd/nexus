<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Class Response
 *
 * Encapsulates the HTTP Response: status, headers, body, and cookies.
 * Provides specialized methods for HTML documents, JSON, redirects,
 * and Alpine.js DOM morphing packets.
 */
final class Response
{
    private int $status;
    private array $headers;
    private string $body;
    private array $cookies = [];

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        return new self($html, $status, $headers);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=UTF-8';
        $encoded = (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new self($encoded, $status, $headers);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /**
     * Specialized response for Alpine Morph SPA DOM updates.
     */
    public static function morph(string $html, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        $headers['X-Nexus-Morph'] = 'true';
        $headers['X-NextPHP-Morph'] = 'true';
        return new self($html, 200, $headers);
    }

    public static function error(string $message, int $status = 500): self
    {
        $safeMsg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $timestamp = date('Y-m-d H:i:s T');
        $rawErrorText = "Error {$status}: {$message}\nTimestamp: {$timestamp}";
        $escapedErrorText = htmlspecialchars($rawErrorText, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error {$status} - Nexus</title>
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
        .error-card {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 16px;
            max-width: 520px;
            width: 100%;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }
        .header {
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
            font-family: ui-monospace, monospace;
        }
        .copy-btn {
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
        h1 {
            color: #ffffff;
            font-size: 1.45rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        p {
            color: #94a3b8;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            background: #030712;
            border: 1px solid #1e293b;
            padding: 1rem;
            border-radius: 8px;
            word-break: break-word;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        p:hover {
            border-color: #38bdf8;
            color: #f1f5f9;
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
            cursor: pointer;
            font-size: 0.88rem;
            font-weight: 600;
            transition: background 0.15s;
        }
        .btn-secondary:hover { background: #334155; color: #ffffff; }
        .hidden { display: none !important; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="header">
            <span class="badge">HTTP {$status}</span>
            <!-- Right-Side Copy Error Button -->
            <button id="copy-err-btn" class="copy-btn" onclick="copyErrorText()" title="Copy error details to clipboard">
                <svg id="copy-icon" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                <svg id="check-icon" class="icon hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span id="copy-btn-txt">Copy Error</span>
            </button>
        </div>
        <h1>Application Error</h1>
        <p onclick="copyErrorText()" title="Click to copy error text">{$safeMsg}</p>
        <div class="actions">
            <a href="/" class="btn-primary">Return Home</a>
            <button class="btn-secondary" onclick="window.location.reload()">Retry</button>
        </div>
    </div>

    <textarea id="raw-err-text" class="hidden">{$escapedErrorText}</textarea>

    <script>
        function copyErrorText() {
            const raw = document.getElementById('raw-err-text').value;
            const btn = document.getElementById('copy-err-btn');
            const txt = document.getElementById('copy-btn-txt');
            const copyIcon = document.getElementById('copy-icon');
            const checkIcon = document.getElementById('check-icon');

            const onSuccess = () => {
                btn.classList.add('copied');
                if (txt) txt.innerText = 'Copied to Clipboard!';
                if (copyIcon) copyIcon.classList.add('hidden');
                if (checkIcon) checkIcon.classList.remove('hidden');

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
                alert('Could not copy error automatically.');
            }
            document.body.removeChild(textarea);
        }
    </script>
</body>
</html>
HTML;

        return self::html($html, $status);
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function setCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httponly = true,
        string $samesite = 'Lax'
    ): self {
        $this->cookies[] = [
            'name'     => $name,
            'value'    => $value,
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ];
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Send HTTP headers, cookies, and body to the browser.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}", true);
            }

            foreach ($this->cookies as $c) {
                setcookie($c['name'], $c['value'], [
                    'expires'  => $c['expires'],
                    'path'     => $c['path'],
                    'domain'   => $c['domain'],
                    'secure'   => $c['secure'],
                    'httponly' => $c['httponly'],
                    'samesite' => $c['samesite'],
                ]);
            }
        }

        echo $this->body;
    }
}
