<?php
namespace BenNeta\Middleware;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use BenNeta\Services\DatabaseService;

/**
 * Cookie and Web Session Management Middleware.
 * Provides persistent browser sessions, theme preferences, DND states,
 * and user consent cookies for the assistant.
 */
class CookieSessionMiddleware implements MiddlewareInterface {
    private DatabaseService $db;

    public function __construct(?DatabaseService $db = null) {
        $this->db = $db ?: new DatabaseService();
    }

    public function handle(Request $request, callable $next): Response {
        // Read or generate session ID
        $sessionId = $request->getCookie('benneta_session_id');
        $isNewSession = false;

        if (empty($sessionId) || !preg_match('/^[a-zA-Z0-9_\-]{16,64}$/', $sessionId)) {
            $sessionId = 'bn_' . bin2hex(random_bytes(16));
            $isNewSession = true;
        }

        // Read theme and DND preferences
        $theme = $request->getCookie('benneta_theme', 'dark');
        $consent = $request->getCookie('benneta_consent', 'pending');
        $dnd = (int)$request->getCookie('benneta_dnd', 0);

        // Update database with active session
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare("
                INSERT INTO user_preferences (session_id, theme, dnd_mode, cookie_consent, last_seen)
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(session_id) DO UPDATE SET 
                    last_seen = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$sessionId, $theme, $dnd, $consent]);
        } catch (\Exception $e) {}

        // Proceed down the middleware pipeline
        $response = $next($request);

        // Attach persistent cookies to response (1 year expiry)
        $oneYear = time() + (365 * 24 * 3600);
        $response->withCookie('benneta_session_id', $sessionId, $oneYear, '/', '', false, true, 'Lax');

        if ($isNewSession) {
            $response->withCookie('benneta_theme', $theme, $oneYear, '/', '', false, false, 'Lax');
            $response->withCookie('benneta_consent', $consent, $oneYear, '/', '', false, false, 'Lax');
        }

        return $response;
    }
}
