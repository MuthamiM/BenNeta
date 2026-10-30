<?php
namespace BenNeta\Core;

use BenNeta\Middleware\MiddlewareInterface;

class Router {
    private array $routes = [];
    private array $middlewares = [];

    public function addMiddleware(MiddlewareInterface $middleware): self {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function register(string $action, $handler): self {
        $this->routes[strtolower($action)] = $handler;
        return $this;
    }

    public function dispatch(Request $request): Response {
        $action = strtolower($request->getAction());
        $handler = $this->routes[$action] ?? null;

        if ($handler === null) {
            return Response::error("Action '{$action}' not found", 404, [
                'error_code' => 'ACTION_NOT_FOUND',
                'available_actions' => array_keys($this->routes)
            ]);
        }

        // Build middleware onion pipeline
        $pipeline = function(Request $req) use ($handler): Response {
            if (is_callable($handler)) {
                return $handler($req);
            }
            if (is_array($handler) && count($handler) === 2) {
                [$class, $method] = $handler;
                $instance = is_object($class) ? $class : new $class();
                return $instance->$method($req);
            }
            return Response::error("Invalid route handler configuration", 500);
        };

        // Wrap middlewares in reverse order
        $layers = array_reverse($this->middlewares);
        foreach ($layers as $middleware) {
            $pipeline = function(Request $req) use ($middleware, $pipeline): Response {
                return $middleware->handle($req, $pipeline);
            };
        }

        return $pipeline($request);
    }
}
