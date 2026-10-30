<?php
namespace BenNeta\Middleware;

use BenNeta\Core\Request;
use BenNeta\Core\Response;

interface MiddlewareInterface {
    /**
     * Handle an incoming request and pass to the next handler.
     *
     * @param Request $request
     * @param callable $next fn(Request $request): Response
     * @return Response
     */
    public function handle(Request $request, callable $next): Response;
}
