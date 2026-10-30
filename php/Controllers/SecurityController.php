<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;

class SecurityController extends BaseController {
    public function getMetrics(Request $request): Response {
        try {
            $totalThreats = (int)($this->db->query("SELECT COUNT(*) as c FROM security_events")[0]['c'] ?? 0);
            $blockedIps = (int)($this->db->query("SELECT COUNT(*) as c FROM security_ip_reputation WHERE is_blocked = 1")[0]['c'] ?? 0);
            $recentEvents = $this->db->query("SELECT * FROM security_events ORDER BY id DESC LIMIT 25");
            $bannedList = $this->db->query("SELECT * FROM security_ip_reputation WHERE is_blocked = 1 ORDER BY last_seen DESC");
            $dnd = $this->db->getSystemState('notifications_mode', 'ON') === 'OFF';

            return $this->success([
                'total_threats_blocked' => $totalThreats,
                'banned_ip_count' => $blockedIps,
                'dnd_active' => $dnd,
                'sentinel_status' => 'ACTIVE',
                'recent_events' => $recentEvents,
                'banned_ips' => $bannedList
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function banIp(Request $request): Response {
        $ip = trim((string)$request->input('ip'));
        if (empty($ip)) {
            return $this->error("IP address required", 400);
        }

        try {
            $this->db->execute("
                INSERT INTO security_ip_reputation (ip, failed_attempts, is_blocked, last_seen)
                VALUES (?, 10, 1, CURRENT_TIMESTAMP)
                ON CONFLICT(ip) DO UPDATE SET is_blocked = 1, last_seen = CURRENT_TIMESTAMP
            ", [$ip]);

            return $this->success(['message' => "IP {$ip} isolated and banned"]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function unbanIp(Request $request): Response {
        $ip = trim((string)$request->input('ip'));
        if (empty($ip)) {
            return $this->error("IP address required", 400);
        }

        try {
            $this->db->execute("UPDATE security_ip_reputation SET is_blocked = 0, failed_attempts = 0 WHERE ip = ?", [$ip]);
            return $this->success(['message' => "IP {$ip} unbanned"]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function toggleDnd(Request $request): Response {
        try {
            $current = $this->db->getSystemState('notifications_mode', 'ON');
            $next = ($current === 'ON') ? 'OFF' : 'ON';
            $this->db->setSystemState('notifications_mode', $next);
            return $this->success([
                'dnd_active' => ($next === 'OFF'),
                'mode' => $next
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
