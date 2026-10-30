<?php
/**
 * Security Microservice Middleware for Assistant API.
 * Sits behind the API to monitor unauthorized access, malicious probes,
 * and unusual behavioral anomalies in real-time.
 * Zero emojis. PC support for Linux and Windows.
 */

class ApiSecurityGuard {
    private PDO $pdo;
    private array $config;
    private string $clientIp;
    private string $userAgent;
    private string $requestUri;

    public function __construct(PDO $pdo, array $config) {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $this->requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    }

    public function inspectRequest(): bool {
        // 1. Check if IP is already blacklisted
        if ($this->isIpBlocked($this->clientIp)) {
            $this->logSecurityEvent("blocked_ip_access", "HIGH", "Blocked IP attempted access to $this->requestUri", true);
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "[ACCESS DENIED] IP is blocked due to security violations."]);
            exit;
        }

        // 2. Detect Malicious Scanner Probes & Path Traversal
        $probeAnomaly = $this->detectMaliciousSignatures();
        if ($probeAnomaly) {
            $this->incrementFailedAttempts($this->clientIp);
            $this->logSecurityEvent($probeAnomaly['type'], "CRITICAL", $probeAnomaly['details'], true);
            $this->triggerAlertNotification("Intrusion Attempt", $probeAnomaly['details']);
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "[SECURITY VIOLATION] " . $probeAnomaly['type']]);
            exit;
        }

        // 3. Detect Rate Spikes & Velocity Anomalies
        $rateAnomaly = $this->checkRateAnomaly();
        if ($rateAnomaly) {
            $this->logSecurityEvent("rate_spike_anomaly", "HIGH", "Rate limit exceeded: >60 requests/min from $this->clientIp", true);
            http_response_code(429);
            echo json_encode(["success" => false, "error" => "[RATE LIMIT ANOMALY] Too many requests."]);
            exit;
        }

        // 4. API Key / Authorization Header Verification (if required)
        $authAnomaly = $this->verifyAuthorization();
        if ($authAnomaly) {
            $this->incrementFailedAttempts($this->clientIp);
            $this->logSecurityEvent("unauthorized_access", "MEDIUM", "Invalid or missing API credentials for $this->requestUri", false);
            // Allow GET status/pending for local dashboard, but protect sensitive POST actions
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$this->isLocalhost($this->clientIp)) {
                http_response_code(401);
                echo json_encode(["success" => false, "error" => "[UNAUTHORIZED] Invalid or missing security token."]);
                exit;
            }
        }

        return true;
    }

    private function isLocalhost(string $ip): bool {
        return in_array($ip, ['127.0.0.1', '::1', 'localhost']);
    }

    private function isIpBlocked(string $ip): bool {
        $stmt = $this->pdo->prepare("SELECT is_blocked FROM security_ip_reputation WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        return $row && (int)$row['is_blocked'] === 1;
    }

    private function incrementFailedAttempts(string $ip): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO security_ip_reputation (ip, failed_attempts, is_blocked, last_seen)
            VALUES (?, 1, 0, CURRENT_TIMESTAMP)
            ON CONFLICT(ip) DO UPDATE SET 
                failed_attempts = failed_attempts + 1,
                last_seen = CURRENT_TIMESTAMP,
                is_blocked = CASE WHEN failed_attempts + 1 >= 5 THEN 1 ELSE is_blocked END
        ");
        $stmt->execute([$ip]);
    }

    private function detectMaliciousSignatures(): ?array {
        $rawInput = file_get_contents('php://input');
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $combined = strtolower($this->requestUri . ' ' . $queryString . ' ' . $rawInput . ' ' . $this->userAgent);

        // Path Traversal
        if (preg_match('/\.\.\/|\.\.\\\\|\/etc\/passwd|win\.ini/', $combined)) {
            return ["type" => "path_traversal", "details" => "Path traversal signature detected in request"];
        }

        // SQL Injection probes
        if (preg_match('/union\s+select|information_schema|\'\s+or\s+\'1\'=\'1|sleep\(\d+\)|benchmark\(/', $combined)) {
            return ["type" => "sql_injection", "details" => "SQL injection signature detected in payload"];
        }

        // Sensitive file scanning probes (.env, wp-login, .git)
        if (preg_match('/\.env|\.git\/|wp-login\.php|phpinfo|eval\(|base64_decode\(/', $combined)) {
            return ["type" => "scanner_probe", "details" => "Malicious file/exploit probe detected"];
        }

        // Known automated vulnerability scanner user-agents
        if (preg_match('/sqlmap|nikto|acunetix|masscan|zgrab|nmap/', strtolower($this->userAgent))) {
            return ["type" => "automated_scanner", "details" => "Vulnerability scanner User-Agent: $this->userAgent"];
        }

        return null;
    }

    private function checkRateAnomaly(): bool {
        // Count requests from this IP in last 60 seconds
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as c FROM security_events 
            WHERE source_ip = ? AND created_at >= datetime('now', '-60 seconds')
        ");
        $stmt->execute([$this->clientIp]);
        $row = $stmt->fetch();
        return ($row && (int)$row['c'] > 60);
    }

    private function verifyAuthorization(): bool {
        // If external non-localhost request, verify X-API-Key or Bearer token
        if ($this->isLocalhost($this->clientIp)) {
            return false; // Localhost allowed
        }

        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? $_GET['api_key'] ?? '';

        $expectedToken = $this->config['security_api_token'] ?? 'ast_sec_token_prod_9821';
        $provided = "";

        if ($apiKey) {
            $provided = $apiKey;
        } elseif (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $provided = trim($matches[1]);
        }

        return ($provided !== $expectedToken);
    }

    public function logSecurityEvent(string $type, string $severity, string $details, bool $blocked): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO security_events (event_type, source_ip, endpoint, severity, details, user_agent, blocked)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$type, $this->clientIp, $this->requestUri, $severity, $details, substr($this->userAgent, 0, 100), $blocked ? 1 : 0]);
        } catch (Exception $e) {}
    }

    private function triggerAlertNotification(string $title, string $msg): void {
        // Send native desktop alert on PC
        $projectRoot = dirname(__DIR__);
        $daemon = $projectRoot . DIRECTORY_SEPARATOR . 'rust' . DIRECTORY_SEPARATOR . 'target' . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'assistant-daemon';
        if (file_exists($daemon)) {
            @shell_exec("\"$daemon\" notify \"[SECURITY] $title\" \"$msg\" > /dev/null 2>&1 &");
        }
    }
}
