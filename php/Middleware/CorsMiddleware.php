<?php
namespace BenNeta\Middleware;

use BenNeta\Core\Request;
use BenNeta\Core\Response;

class CorsMiddleware implements MiddlewareInterface {
    public function handle(Request $request, callable $next): Response {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-API-Token',
                'Access-Control-Max-Age' => '86400'
            ]);
        }

        $response = $next($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-API-Token');
    }
}
