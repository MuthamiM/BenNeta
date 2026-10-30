<?php
namespace BenNeta\Middleware;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use BenNeta\Services\DatabaseService;
use BenNeta\Services\SystemService;

/**
 * Usage Tracking & Data Collection Middleware.
 * Automatically logs assistant interactions, task executions, response times,
 * and memory metrics to the database for user analytics and audits.
 */
class UsageTrackingMiddleware implements MiddlewareInterface {
    private DatabaseService $db;

    public function __construct(?DatabaseService $db = null) {
        $this->db = $db ?: new DatabaseService();
    }

    public function handle(Request $request, callable $next): Response {
        $startTime = microtime(true);
        $action = $request->getAction();
        $sessionId = $request->getCookie('benneta_session_id', 'unknown');

        // Execute request
        $response = $next($request);

        $durationMs = (int)(round((microtime(true) - $startTime) * 1000));
        $ramMb = SystemService::getCurrentRssMb();
        $status = ($response->getStatusCode() < 400) ? 'success' : 'error';

        // Categorize action
        $category = 'general';
        if (strpos($action, 'gmail') !== false || strpos($action, 'email') !== false) {
            $category = 'email';
        } elseif (strpos($action, 'security') !== false || strpos($action, 'ban') !== false) {
            $category = 'security';
        } elseif (strpos($action, 'task') !== false || strpos($action, 'todo') !== false) {
            $category = 'task';
        } elseif (strpos($action, 'research') !== false || strpos($action, 'brave') !== false || strpos($action, 'draft') !== false) {
            $category = 'research';
        } elseif (strpos($action, 'download') !== false) {
            $category = 'download';
        } elseif (strpos($action, 'usage') !== false || strpos($action, 'analytics') !== false) {
            $category = 'analytics';
        }

        // Record telemetry event into database
        $this->db->logUsageEvent(
            'api_request',
            $category,
            $action,
            $status,
            $durationMs,
            $ramMb,
            [
                'method' => $request->getMethod(),
                'status_code' => $response->getStatusCode(),
                'path' => $request->getPath()
            ],
            $sessionId,
            $request->getClientIp(),
            $request->getUserAgent()
        );

        return $response;
    }
}
