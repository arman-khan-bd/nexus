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
        return self::html(
            "<!DOCTYPE html><html><head><title>Error {$status}</title><style>body{background:#090d16;color:#f1f5f9;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}div{border:1px solid #1e293b;background:#0f172a;padding:2rem;border-radius:12px;text-align:center;}h1{color:#ef4444;margin-top:0;}</style></head><body><div><h1>Error {$status}</h1><p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p></div></body></html>",
            $status
        );
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
