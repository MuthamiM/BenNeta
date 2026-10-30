<?php
namespace BenNeta\Services;

class ConfigService {
    private string $configPath;
    private ?array $cache = null;

    public function __construct(?string $projectRoot = null) {
        $root = $projectRoot ?: dirname(dirname(__DIR__));
        $this->configPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.json';
    }

    public function get(?string $key = null, $default = null) {
        if ($this->cache === null) {
            if (file_exists($this->configPath)) {
                $raw = @file_get_contents($this->configPath);
                $this->cache = json_decode($raw, true) ?: [];
            } else {
                $this->cache = [];
            }
        }

        if ($key === null) {
            return $this->cache;
        }

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, $value): void {
        $all = $this->get();
        $all[$key] = $value;
        $this->cache = $all;
        @file_put_contents($this->configPath, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function saveAll(array $data): void {
        $this->cache = $data;
        @file_put_contents($this->configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function hasGmailConfig(): bool {
        $email = $this->get('gmail_address');
        $pwd = $this->get('gmail_app_password');
        return !empty($email) && !empty($pwd);
    }

    public function getBraveApiKey(): string {
        return (string)$this->get('brave_search_api_key', '');
    }
}
