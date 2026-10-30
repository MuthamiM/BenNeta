<?php
/**
 * BenNeta Microservices API Entrypoint.
 * Architecture: Clean Controller-Middleware-Service Pattern.
 * Memory Budget: Strict Max 28MB RAM Constraint.
 */

// Strict memory cap guard
@ini_set('memory_limit', '24M');

require_once dirname(__DIR__) . '/php/autoload.php';

use BenNeta\Core\App;

$app = new App();
$app->run();
