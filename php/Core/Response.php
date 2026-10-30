<?php
namespace BenNeta\Core;

/**
 * Lightweight, memory-conscious HTTP Response abstraction.
 * Supports JSON serialization, cookie manipulation, and status codes.
 */
class Response {
    private int $statusCode;
    private array $headers = [];
    private array $cookies = [];
    private $content;

    public function __construct($content = null, int $statusCode = 200, array $headers = []) {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function json($data, int $statusCode = 200, array $headers = []): self {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return new self($data, $statusCode, $headers);
    }

    public static function error(string $message, int $statusCode = 400, array $extra = []): self {
        $payload = array_merge(['status' => 'ERROR', 'error' => $message], $extra);
        return self::json($payload, $statusCode);
    }

    public static function success(array $data = [], int $statusCode = 200): self {
        $payload = array_merge(['status' => 'SUCCESS'], $data);
        return self::json($payload, $statusCode);
    }

    public function withHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withCookie(string $name, string $value, int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = true, string $samesite = 'Lax'): self {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite
        ];
        return $this;
    }

    public function send(): void {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        foreach ($this->cookies as $c) {
            if (PHP_VERSION_ID >= 70300) {
                setcookie($c['name'], $c['value'], [
                    'expires' => $c['expire'],
                    'path' => $c['path'],
                    'domain' => $c['domain'],
                    'secure' => $c['secure'],
                    'httponly' => $c['httponly'],
                    'samesite' => $c['samesite']
                ]);
            } else {
                setcookie($c['name'], $c['value'], $c['expire'], $c['path'], $c['domain'], $c['secure'], $c['httponly']);
            }
        }

        if (is_array($this->content) || is_object($this->content)) {
            echo json_encode($this->content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($this->content !== null) {
            echo $this->content;
        }

        // Force memory garbage collection cycle to stay strictly within 28MB budget
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getContent() {
        return $this->content;
    }
}
