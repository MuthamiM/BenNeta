<?php
/**
 * Lightweight Built-in PHP Router for BenNeta.
 * Routes:
 *   /              -> Landing Page (OS detection, .deb / .exe downloads)
 *   /app           -> BenNeta Live Assistant Dashboard
 *   /dashboard     -> Alias to Dashboard
 *   /download      -> Alias to Landing Page
 *   /api.php       -> Microservices REST API (Controllers & Middlewares)
 *   /downloads/*   -> Binary distributions (.deb and .zip)
 *   /assets/*      -> Design system and icons
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/' || $uri === '/index.html' || $uri === '/download' || $uri === '/download.html') {
    include __DIR__ . '/index.html';
    exit;
}

if ($uri === '/signup' || $uri === '/signup.html' || $uri === '/login') {
    include __DIR__ . '/signup.html';
    exit;
}

if ($uri === '/app' || $uri === '/app/' || $uri === '/dashboard' || $uri === '/dashboard.html' || $uri === '/app.html') {
    include __DIR__ . '/app.html';
    exit;
}

if ($uri === '/api.php') {
    require __DIR__ . '/api.php';
    exit;
}

$file = __DIR__ . $uri;
if (file_exists($file) && !is_dir($file)) {
    return false; // Built-in PHP server handles static file directly
}

// Default 404 or fallback
http_response_code(404);
include __DIR__ . '/index.html';
