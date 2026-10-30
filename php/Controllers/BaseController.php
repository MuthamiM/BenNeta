<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Response;
use BenNeta\Services\DatabaseService;
use BenNeta\Services\ConfigService;
use BenNeta\Services\ProcessRunner;

abstract class BaseController {
    protected DatabaseService $db;
    protected ConfigService $config;
    protected ProcessRunner $runner;

    public function __construct() {
        $this->db = new DatabaseService();
        $this->config = new ConfigService();
        $this->runner = new ProcessRunner();
    }

    protected function json($data, int $status = 200): Response {
        return Response::json($data, $status);
    }

    protected function success(array $data = [], int $status = 200): Response {
        return Response::success($data, $status);
    }

    protected function error(string $message, int $status = 400, array $extra = []): Response {
        return Response::error($message, $status, $extra);
    }
}
