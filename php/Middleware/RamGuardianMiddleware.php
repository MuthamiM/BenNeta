<?php
namespace BenNeta\Middleware;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use BenNeta\Services\SystemService;
use BenNeta\Services\DatabaseService;

/**
 * Strict RAM Guardian Middleware.
 * Enforces the strict maximum 28MB RAM constraint requested by the user.
 * Monitors RSS before and after every request, triggers garbage collection,
 * logs warnings if nearing budget, and injects memory telemetry headers.
 */
class RamGuardianMiddleware implements MiddlewareInterface {
    private DatabaseService $db;

    public function __construct(?DatabaseService $db = null) {
        $this->db = $db ?: new DatabaseService();
    }

    public function handle(Request $request, callable $next): Response {
        $startRam = SystemService::getCurrentRssMb();

        // Enforce internal PHP memory limit safely below 28M
        @ini_set('memory_limit', '24M');

        $response = $next($request);

        // Run garbage collection after request execution
        SystemService::optimizeMemory();
        $endRam = SystemService::getCurrentRssMb();
        $ramAudit = SystemService::getRamAudit();

        // If RAM is over 24MB (approaching 28MB cap), log a warning to database
        if ($endRam >= (SystemService::MAX_RAM_LIMIT_MB * 0.85)) {
            try {
                $this->db->execute("
                    INSERT INTO ram_telemetry (process_name, rss_mb, vms_mb, max_limit_mb, percent_of_max, status)
                    VALUES ('web_api', ?, 0, 28.0, ?, ?)
                ", [$endRam, $ramAudit['percent_used'], $ramAudit['status']]);
            } catch (\Exception $e) {}
        }

        return $response
            ->withHeader('X-RAM-Usage-MB', (string)$endRam)
            ->withHeader('X-RAM-Limit-MB', '28.0')
            ->withHeader('X-RAM-Status', $ramAudit['status'])
            ->withHeader('X-RAM-Percent', (string)$ramAudit['percent_used']);
    }
}
