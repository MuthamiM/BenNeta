<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use BenNeta\Services\SystemService;

class UsageController extends BaseController {
    public function getUsageData(Request $request): Response {
        try {
            // RAM status against 28MB budget
            $ram = SystemService::getRamAudit();

            // Aggregated telemetry stats
            $totalToday = (int)($this->db->query("
                SELECT COUNT(*) as c FROM usage_events 
                WHERE date(created_at) = date('now')
            ")[0]['c'] ?? 0);

            $categoryCounts = $this->db->query("
                SELECT category, COUNT(*) as count 
                FROM usage_events 
                WHERE date(created_at) = date('now')
                GROUP BY category
            ");

            // Hourly breakdown
            $hourly = $this->db->query("
                SELECT strftime('%H:00', created_at) as hour, COUNT(*) as count
                FROM usage_events
                WHERE date(created_at) = date('now')
                GROUP BY strftime('%H:00', created_at)
                ORDER BY hour ASC
            ");

            // Recent activity log (last 30 events)
            $recentEvents = $this->db->query("
                SELECT id, event_type, category, action_name, status, duration_ms, ram_usage_mb, created_at
                FROM usage_events
                ORDER BY id DESC
                LIMIT 30
            ");

            // Active user preferences
            $sessionId = $request->getCookie('benneta_session_id', 'unknown');
            $userPrefs = $this->db->query("
                SELECT * FROM user_preferences WHERE session_id = ?
            ", [$sessionId]);

            return $this->success([
                'ram_audit' => $ram,
                'total_actions_today' => $totalToday,
                'categories' => $categoryCounts,
                'hourly_distribution' => $hourly,
                'recent_activity' => $recentEvents,
                'session' => [
                    'session_id' => $sessionId,
                    'preferences' => $userPrefs[0] ?? [
                        'theme' => $request->getCookie('benneta_theme', 'dark'),
                        'cookie_consent' => $request->getCookie('benneta_consent', 'accepted')
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error("Failed to query usage metrics: " . $e->getMessage(), 500);
        }
    }

    public function trackClientEvent(Request $request): Response {
        $eventType = trim((string)$request->input('event_type', 'ui_interaction'));
        $category = trim((string)$request->input('category', 'web'));
        $action = trim((string)$request->input('action_name', 'button_click'));
        $duration = (int)$request->input('duration_ms', 0);
        $details = $request->input('details');
        $sessionId = $request->getCookie('benneta_session_id', 'anonymous');

        $this->db->logUsageEvent(
            $eventType,
            $category,
            $action,
            'success',
            $duration,
            SystemService::getCurrentRssMb(),
            is_array($details) ? $details : ['raw' => $details],
            $sessionId,
            $request->getClientIp(),
            $request->getUserAgent()
        );

        return $this->success(['message' => 'Telemetry event logged']);
    }

    public function exportData(Request $request): Response {
        try {
            $export = [
                'metadata' => [
                    'app' => 'BenNeta PC Intelligence & Automation',
                    'version' => '1.0.0',
                    'exported_at' => date('c'),
                    'ram_limit_mb' => 28.0
                ],
                'usage_events' => $this->db->query("SELECT * FROM usage_events ORDER BY id DESC LIMIT 500"),
                'security_events' => $this->db->query("SELECT * FROM security_events ORDER BY id DESC LIMIT 100"),
                'tasks' => $this->db->query("SELECT * FROM daily_tasks ORDER BY id DESC"),
                'system_state' => $this->db->query("SELECT * FROM system_state")
            ];

            return Response::json($export, 200, [
                'Content-Disposition' => 'attachment; filename="benneta_user_data_' . date('Ymd_His') . '.json"'
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function clearData(Request $request): Response {
        try {
            $this->db->execute("DELETE FROM usage_events");
            return $this->success(['message' => 'All collected usage telemetry cleared']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function savePreferences(Request $request): Response {
        $theme = trim((string)$request->input('theme', 'dark'));
        $dnd = (int)$request->input('dnd_mode', 0);
        $consent = trim((string)$request->input('cookie_consent', 'accepted'));
        $sessionId = $request->getCookie('benneta_session_id', 'bn_' . bin2hex(random_bytes(16)));

        try {
            $this->db->execute("
                INSERT INTO user_preferences (session_id, theme, dnd_mode, cookie_consent, last_seen)
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(session_id) DO UPDATE SET
                    theme = excluded.theme,
                    dnd_mode = excluded.dnd_mode,
                    cookie_consent = excluded.cookie_consent,
                    last_seen = CURRENT_TIMESTAMP
            ", [$sessionId, $theme, $dnd, $consent]);

            $oneYear = time() + (365 * 24 * 3600);
            $response = $this->success([
                'message' => 'Preferences saved',
                'theme' => $theme,
                'dnd_mode' => $dnd,
                'cookie_consent' => $consent
            ]);
            $response->withCookie('benneta_theme', $theme, $oneYear, '/', '', false, false, 'Lax');
            $response->withCookie('benneta_dnd', (string)$dnd, $oneYear, '/', '', false, false, 'Lax');
            $response->withCookie('benneta_consent', $consent, $oneYear, '/', '', false, false, 'Lax');

            return $response;
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
