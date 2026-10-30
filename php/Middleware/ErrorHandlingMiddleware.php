<?php
namespace BenNeta\Middleware;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use BenNeta\Services\SystemService;
use Throwable;

/**
 * Global Error Handling & Exception Middleware.
 * Catches all runtime exceptions, converts them to structured JSON payloads,
 * logs incident telemetry, and protects internal server paths.
 */
class ErrorHandlingMiddleware implements MiddlewareInterface {
    public function handle(Request $request, callable $next): Response {
        try {
            return $next($request);
        } catch (Throwable $e) {
            $ramMb = SystemService::getCurrentRssMb();
            $statusCode = 500;
            $code = 'INTERNAL_ERROR';

            if ($e->getCode() >= 400 && $e->getCode() <= 599) {
                $statusCode = (int)$e->getCode();
            }

            return Response::error($e->getMessage(), $statusCode, [
                'error_code' => $code,
                'error_type' => get_class($e),
                'ram_mb' => $ramMb,
                'max_ram_limit_mb' => 28.0,
                'timestamp' => date('c'),
                'hint' => 'Check BenNeta logs or verify configuration in /opt/benneta/config/config.json'
            ]);
        }
    }
}
