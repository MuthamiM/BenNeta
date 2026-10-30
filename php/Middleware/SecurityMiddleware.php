<?php
namespace BenNeta\Middleware;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use BenNeta\Services\DatabaseService;

/**
 * Security Sentinel Middleware.
 * Analyzes requests for exploit signatures, enforces IP reputation,
 * and rate-limits abusive clients to protect assistant microservices.
 */
class SecurityMiddleware implements MiddlewareInterface {
    private DatabaseService $db;
    private array $exploitSignatures = [
        'sql_injection' => '/(\b(union\s+select|select\s+.*\s+from|insert\s+into|drop\s+table|delete\s+from|alter\s+table)\b|--|\/\*|\*\/)/i',
        'xss' => '/(<script\b[^>]*>.*?<\/script>|javascript:|onerror\s*=|onload\s*=|alert\(|eval\()/i',
        'path_traversal' => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\/)/i',
        'shell_injection' => '/(;\s*(?:cat|ls|rm|chmod|chown|wget|curl|nc|bash|sh|kill|reboot|sudo|python|perl|ruby)\b|\&\&\s*[a-z]|\b\|\|\s*[a-z]|`|\$\([^\)]*\)|\/bin\/(sh|bash|zsh))/i',
        'credential_stuffing' => '/(\badmin\'\s*or\b|\bpassword\'\s*or\b|\b1=1\b)/i'
    ];

    public function __construct(?DatabaseService $db = null) {
        $this->db = $db ?: new DatabaseService();
    }

    public function handle(Request $request, callable $next): Response {
        $ip = $request->getClientIp();
        $pdo = $this->db->getPdo();

        // 1. Check if IP is currently blocked (exempt loopback IPs)
        if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
            try {
                $stmt = $pdo->prepare("SELECT is_blocked, failed_attempts FROM security_ip_reputation WHERE ip = ?");
                $stmt->execute([$ip]);
                $rep = $stmt->fetch();
                if ($rep && (int)$rep['is_blocked'] === 1) {
                    return Response::error('Access Denied. Your IP address has been isolated by BenNeta Security Sentinel.', 403, [
                        'ip' => $ip,
                        'reason' => 'IP_REPUTATION_BLOCKED',
                        'error_code' => 'SECURITY_IP_BLOCKED'
                    ]);
                }
            } catch (\Exception $e) {}
        }

        // 2. Scan request parameters and payloads for exploit signatures
        $payloadStr = json_encode($request->all());

        foreach ($this->exploitSignatures as $attackType => $regex) {
            if (preg_match($regex, $payloadStr)) {
                $this->recordSecurityIncident($ip, $request->getPath(), $attackType, $request->getUserAgent(), $payloadStr);
                return Response::error("Security Sentinel Intrusion Blocked: {$attackType} attempt detected.", 403, [
                    'attack_type' => $attackType,
                    'error_code' => 'EXPLOIT_DETECTED'
                ]);
            }
        }

        return $next($request);
    }

    private function recordSecurityIncident(string $ip, string $endpoint, string $type, string $ua, string $details): void {
        try {
            $pdo = $this->db->getPdo();
            // Log security incident
            $stmt = $pdo->prepare("
                INSERT INTO security_events (event_type, source_ip, endpoint, severity, details, user_agent, blocked)
                VALUES (?, ?, ?, 'HIGH', ?, ?, 1)
            ");
            $stmt->execute([$type, $ip, $endpoint, substr($details, 0, 500), $ua]);

            // Increment failed attempts
            $stmtRep = $pdo->prepare("
                INSERT INTO security_ip_reputation (ip, failed_attempts, is_blocked, last_seen)
                VALUES (?, 1, 0, CURRENT_TIMESTAMP)
                ON CONFLICT(ip) DO UPDATE SET 
                    failed_attempts = failed_attempts + 1,
                    last_seen = CURRENT_TIMESTAMP,
                    is_blocked = CASE WHEN failed_attempts + 1 >= 5 THEN 1 ELSE is_blocked END
            ");
            $stmtRep->execute([$ip]);
        } catch (\Exception $e) {}
    }
}
