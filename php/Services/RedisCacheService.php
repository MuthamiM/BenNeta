<?php
namespace BenNeta\Services;

/**
 * Lightweight, zero-dependency Redis client for EXER.
 * Uses native PHP streams/sockets (< 1KB RAM footprint).
 * Gracefully degrades if Redis is unavailable.
 */
class RedisCacheService {
    private static ?self $instance = null;
    private $socket = null;
    private string $host;
    private int $port;
    private float $timeout;
    private bool $connected = false;

    public function __construct(string $host = 'redis', int $port = 6379, float $timeout = 0.5) {
        $this->host = getenv('REDIS_HOST') ?: $host;
        $this->port = (int)(getenv('REDIS_PORT') ?: $port);
        $this->timeout = $timeout;
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect(): bool {
        if ($this->connected && is_resource($this->socket) && !feof($this->socket)) {
            return true;
        }

        try {
            $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
            if ($this->socket) {
                stream_set_timeout($this->socket, 1);
                $this->connected = true;
                return true;
            }
        } catch (\Throwable $t) {
            $this->connected = false;
        }
        $this->connected = false;
        return false;
    }

    private function sendCommand(array $args) {
        if (!$this->connect()) {
            return null;
        }

        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $argStr = (string)$arg;
            $cmd .= '$' . strlen($argStr) . "\r\n" . $argStr . "\r\n";
        }

        if (@fwrite($this->socket, $cmd) === false) {
            $this->connected = false;
            return null;
        }

        return $this->parseResponse();
    }

    private function parseResponse() {
        if (!$this->socket || feof($this->socket)) {
            $this->connected = false;
            return null;
        }

        $line = fgets($this->socket);
        if ($line === false) {
            $this->connected = false;
            return null;
        }

        $prefix = $line[0];
        $payload = substr($line, 1, -2);

        switch ($prefix) {
            case '+':
                return $payload;
            case ':':
                return (int)$payload;
            case '$':
                $length = (int)$payload;
                if ($length === -1) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $length) {
                    $buf = fread($this->socket, $length - strlen($data));
                    if ($buf === false) break;
                    $data .= $buf;
                }
                fread($this->socket, 2); // Consume trailing \r\n
                return $data;
            case '*':
                $count = (int)$payload;
                if ($count === -1) {
                    return null;
                }
                $arr = [];
                for ($i = 0; $i < $count; $i++) {
                    $arr[] = $this->parseResponse();
                }
                return $arr;
            case '-':
            default:
                return null;
        }
    }

    public function get(string $key) {
        try {
            $res = $this->sendCommand(['GET', $key]);
            if ($res !== null) {
                $decoded = json_decode($res, true);
                return $decoded !== null ? $decoded : $res;
            }
        } catch (\Throwable $t) {}
        return null;
    }

    public function set(string $key, $value, int $ttl = 3600): bool {
        try {
            $encoded = is_string($value) ? $value : json_encode($value);
            if ($ttl > 0) {
                $res = $this->sendCommand(['SETEX', $key, $ttl, $encoded]);
            } else {
                $res = $this->sendCommand(['SET', $key, $encoded]);
            }
            return $res === 'OK';
        } catch (\Throwable $t) {}
        return false;
    }

    public function del(string ...$keys): int {
        try {
            if (empty($keys)) return 0;
            $res = $this->sendCommand(array_merge(['DEL'], $keys));
            return is_numeric($res) ? (int)$res : 0;
        } catch (\Throwable $t) {}
        return 0;
    }

    public function deletePrefix(string $prefix): void {
        try {
            $keys = $this->sendCommand(['KEYS', $prefix . '*']);
            if (is_array($keys) && !empty($keys)) {
                $this->del(...$keys);
            }
        } catch (\Throwable $t) {}
    }
}
