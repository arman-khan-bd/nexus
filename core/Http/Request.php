<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Class Request
 *
 * Immutable, hardened HTTP Request abstraction.
 * Provides parameter normalization, server action identification,
 * trusted IP extraction, and route payload bindings.
 */
final class Request
{
    private string $method;
    private string $path;
    private string $rawUri;
    private array $query;
    private array $post;
    private array $headers;
    private array $cookies;
    private array $files;
    private array $server;
    private string $rawBody;
    private ?array $jsonBody = null;
    private array $routeParams = [];

    public function __construct(
        string $method,
        string $rawUri,
        string $path,
        array $query,
        array $post,
        array $headers,
        array $cookies,
        array $files,
        array $server,
        string $rawBody = ''
    ) {
        $this->method = strtoupper($method);
        $this->rawUri = $rawUri;
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->headers = $headers;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->rawBody = $rawBody;
    }

    /**
     * Capture request from PHP superglobals.
     */
    public static function capture(): self
    {
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $parsedPath = parse_url($rawUri, PHP_URL_PATH) ?: '/';
        $path = rtrim($parsedPath, '/');
        if ($path === '') {
            $path = '/';
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $query = $_GET;
        $post = $_POST;
        $cookies = $_COOKIE;
        $files = $_FILES;
        $server = $_SERVER;

        // Parse headers from $_SERVER
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$headerName] = (string)$v;
            } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headerName = strtolower(str_replace('_', '-', $k));
                $headers[$headerName] = (string)$v;
            }
        }

        $rawBody = (string)file_get_contents('php://input');

        return new self(
            $method,
            $rawUri,
            $path,
            $query,
            $post,
            $headers,
            $cookies,
            $files,
            $server,
            $rawBody
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getRawUri(): string
    {
        return $this->rawUri;
    }

    public function getUri(): string
    {
        return $this->rawUri;
    }

    public function getQuery(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function getPost(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    public function getJson(): array
    {
        if ($this->jsonBody === null) {
            $decoded = json_decode($this->rawBody, true);
            $this->jsonBody = is_array($decoded) ? $decoded : [];
        }
        return $this->jsonBody;
    }

    public function getInput(?string $key = null, mixed $default = null): mixed
    {
        $all = array_merge($this->query, $this->post, $this->getJson());
        if ($key === null) {
            return $all;
        }
        return $all[$key] ?? $default;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $normalized = strtolower($name);
        return $this->headers[$normalized] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getCookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function getServer(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }
        return $this->server[$key] ?? $default;
    }

    /**
     * Get client IP address securely.
     */
    public function getIp(): string
    {
        // On cPanel, REMOTE_ADDR is reliable unless proxy configured
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
    }

    public function getUserAgent(): string
    {
        return $this->getHeader('user-agent', 'Unknown');
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return (!empty($https) && $https !== 'off') ||
               ($this->getHeader('x-forwarded-proto') === 'https') ||
               (($this->server['SERVER_PORT'] ?? 80) == 443);
    }

    public function isAjax(): bool
    {
        return $this->getHeader('x-requested-with') === 'xmlhttprequest' ||
               str_contains($this->getHeader('accept', ''), 'application/json');
    }

    /**
     * Check if request is a Nexus SPA navigation request.
     */
    public function isSpa(): bool
    {
        return $this->getHeader('x-nexus-spa') === 'true' ||
               $this->getHeader('x-nextphp-spa') === 'true' ||
               $this->getQuery('_spa') === '1';
    }

    /**
     * Check if request is a Nexus SPA Server Action.
     */
    public function isServerAction(): bool
    {
        return $this->hasHeader('x-nexus-action') ||
               $this->hasHeader('x-nextphp-action') ||
               isset($this->post['_action']) ||
               isset($this->getJson()['_action']) ||
               isset($this->post['action']) ||
               isset($this->getJson()['action']);
    }

    /**
     * Get Server Action method/identifier.
     */
    public function getActionName(): ?string
    {
        if ($this->hasHeader('x-nexus-action')) {
            return $this->getHeader('x-nexus-action');
        }
        if ($this->hasHeader('x-nextphp-action')) {
            return $this->getHeader('x-nextphp-action');
        }
        if (isset($this->post['_action']) && is_string($this->post['_action'])) {
            return $this->post['_action'];
        }
        if (isset($this->post['action']) && is_string($this->post['action'])) {
            return $this->post['action'];
        }
        $json = $this->getJson();
        if (isset($json['_action']) && is_string($json['_action'])) {
            return $json['_action'];
        }
        if (isset($json['action']) && is_string($json['action'])) {
            return $json['action'];
        }
        return null;
    }

    /**
     * Set dynamic route parameters matching Next.js brackets: [id], [slug], etc.
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function getRouteParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }
}
