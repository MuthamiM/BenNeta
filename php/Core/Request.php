<?php
namespace BenNeta\Core;

/**
 * Lightweight, memory-conscious HTTP Request abstraction.
 * Keeps memory allocation minimal to satisfy <= 28MB RAM budget.
 */
class Request {
    private array $get;
    private array $post;
    private array $cookies;
    private array $server;
    private ?array $jsonBody = null;

    public function __construct(array $get = [], array $post = [], array $cookies = [], array $server = []) {
        $this->get = $get ?: $_GET;
        $this->post = $post ?: $_POST;
        $this->cookies = $cookies ?: $_COOKIE;
        $this->server = $server ?: $_SERVER;
    }

    public static function capture(): self {
        return new self($_GET, $_POST, $_COOKIE, $_SERVER);
    }

    public function getMethod(): string {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function getUri(): string {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function getPath(): string {
        $uri = $this->getUri();
        $pos = strpos($uri, '?');
        return ($pos !== false) ? substr($uri, 0, $pos) : $uri;
    }

    public function getAction(): string {
        return $this->get['action'] ?? $this->post['action'] ?? 'status';
    }

    public function getQuery(string $key, $default = null) {
        return $this->get[$key] ?? $default;
    }

    public function getPost(string $key, $default = null) {
        return $this->post[$key] ?? $default;
    }

    public function getCookie(string $key, $default = null) {
        return $this->cookies[$key] ?? $default;
    }

    public function getJson(): array {
        if ($this->jsonBody === null) {
            $raw = file_get_contents('php://input');
            $this->jsonBody = (!empty($raw)) ? (json_decode($raw, true) ?: []) : [];
        }
        return $this->jsonBody;
    }

    public function input(string $key, $default = null) {
        $json = $this->getJson();
        if (isset($json[$key])) return $json[$key];
        if (isset($this->post[$key])) return $this->post[$key];
        if (isset($this->get[$key])) return $this->get[$key];
        return $default;
    }

    public function all(): array {
        return array_merge($this->get, $this->post, $this->getJson());
    }

    public function getClientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        foreach ($headers as $h) {
            if (!empty($this->server[$h])) {
                $parts = explode(',', $this->server[$h]);
                return trim($parts[0]);
            }
        }
        return '127.0.0.1';
    }

    public function getUserAgent(): string {
        return $this->server['HTTP_USER_AGENT'] ?? 'Unknown/1.0';
    }

    public function getHeader(string $name, $default = null) {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$normalized] ?? $default;
    }
}
