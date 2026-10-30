<?php
namespace BenNeta\Services;

use PDO;
use Exception;

class DatabaseService {
    private static ?PDO $pdo = null;
    private string $projectRoot;
    private string $dbPath;

    public function __construct(?string $projectRoot = null) {
        $this->projectRoot = $projectRoot ?: dirname(dirname(__DIR__));
        $this->dbPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'assistant.db';
    }

    public function getPdo(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        if (!file_exists($this->dbPath)) {
            $cmd = (DIRECTORY_SEPARATOR === '\\') 
                ? "python \"" . $this->projectRoot . "\\python\\db.py\"" 
                : "python3 \"" . $this->projectRoot . "/python/db.py\"";
            @shell_exec($cmd);
        }

        self::$pdo = new PDO('sqlite:' . $this->dbPath);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Performance & ultra-low memory pragmas for SQLite
        self::$pdo->exec("PRAGMA journal_mode = WAL;");
        self::$pdo->exec("PRAGMA synchronous = NORMAL;");
        self::$pdo->exec("PRAGMA cache_size = -2000;"); // ~2MB cache
        
        return self::$pdo;
    }

    public function query(string $sql, array $params = []): array {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function execute(string $sql, array $params = []): int {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function getSystemState(string $key, ?string $default = null): ?string {
        try {
            $stmt = $this->getPdo()->prepare("SELECT value FROM system_state WHERE key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? $row['value'] : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    public function setSystemState(string $key, string $value): void {
        $stmt = $this->getPdo()->prepare("
            INSERT INTO system_state (key, value, updated_at) 
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$key, $value]);
    }

    public function logUsageEvent(string $type, string $category, ?string $action = null, 
                                  string $status = 'success', int $durationMs = 0, 
                                  float $ramMb = 0.0, ?array $details = null, 
                                  ?string $sessionId = null, ?string $ip = null, 
                                  ?string $ua = null): void {
        try {
            $stmt = $this->getPdo()->prepare("
                INSERT INTO usage_events (event_type, category, action_name, status,
                                          duration_ms, ram_usage_mb, details_json,
                                          session_id, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $type, $category, $action, $status, $durationMs, $ramMb,
                $details ? json_encode($details) : null,
                $sessionId, $ip, $ua
            ]);
        } catch (Exception $e) {}
    }
}
