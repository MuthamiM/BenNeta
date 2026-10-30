<?php
namespace BenNeta\Services;

class SystemService {
    public const MAX_RAM_LIMIT_MB = 28.0;

    /**
     * Get current PHP process RSS (Resident Set Size) in megabytes.
     * Uses /proc/self/status on Linux for physical memory accuracy,
     * falls back to memory_get_usage(true).
     */
    public static function getCurrentRssMb(): float {
        if (DIRECTORY_SEPARATOR !== '\\' && file_exists('/proc/self/status')) {
            $status = @file_get_contents('/proc/self/status');
            if ($status && preg_match('/VmRSS:\s+(\d+)\s+kB/i', $status, $m)) {
                return round(((int)$m[1]) / 1024.0, 2);
            }
        }
        return round(memory_get_usage(true) / (1024.0 * 1024.0), 2);
    }

    /**
     * Audit RAM against the strict 28MB budget.
     */
    public static function getRamAudit(): array {
        $rss = self::getCurrentRssMb();
        $limit = self::MAX_RAM_LIMIT_MB;
        $pct = round(($rss / $limit) * 100, 1);
        
        $status = 'OK';
        if ($rss >= $limit) {
            $status = 'ALERT_EXCEEDED';
        } elseif ($rss >= ($limit * 0.85)) {
            $status = 'WARNING_HIGH';
        }

        return [
            'rss_mb' => $rss,
            'limit_mb' => $limit,
            'percent_used' => $pct,
            'remaining_mb' => max(0.0, round($limit - $rss, 2)),
            'status' => $status,
            'strict_enforced' => true,
            'timestamp' => date('c')
        ];
    }

    /**
     * Enforce garbage collection to keep RSS minimal.
     */
    public static function optimizeMemory(): void {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    /**
     * Gather system health metrics (CPU, disk, memory).
     */
    public static function getMetrics(): array {
        $ram = self::getRamAudit();
        $diskFree = @disk_free_space('/');
        $diskTotal = @disk_total_space('/');
        $diskFreeGb = $diskTotal ? round($diskFree / (1024 * 1024 * 1024), 2) : 0;
        $diskTotalGb = $diskTotal ? round($diskTotal / (1024 * 1024 * 1024), 2) : 0;

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        return [
            'ram' => $ram,
            'disk' => [
                'free_gb' => $diskFreeGb,
                'total_gb' => $diskTotalGb,
                'used_pct' => $diskTotal ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 1) : 0
            ],
            'load_avg' => $load,
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY
        ];
    }
}
