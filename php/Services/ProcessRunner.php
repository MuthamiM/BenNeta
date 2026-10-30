<?php
namespace BenNeta\Services;

class ProcessRunner {
    private string $projectRoot;

    public function __construct(?string $projectRoot = null) {
        $this->projectRoot = $projectRoot ?: dirname(dirname(__DIR__));
    }

    public function runPython(string $scriptRelativePath, array $args = [], int $timeoutSec = 15): array {
        $isWin = (DIRECTORY_SEPARATOR === '\\');
        $pythonBin = $isWin ? 'python' : 'python3';
        $fullPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . $scriptRelativePath;

        if (!file_exists($fullPath)) {
            return ['code' => 1, 'output' => '', 'error' => "Script not found: $scriptRelativePath"];
        }

        $cmdArgs = [$pythonBin, escapeshellarg($fullPath)];
        foreach ($args as $a) {
            $cmdArgs[] = escapeshellarg($a);
        }
        $cmd = implode(' ', $cmdArgs) . ' 2>&1';

        $output = [];
        $returnCode = 0;
        @exec($cmd, $output, $returnCode);

        // Run memory optimization after subprocess completes
        SystemService::optimizeMemory();

        return [
            'code' => $returnCode,
            'output' => implode("\n", $output),
            'success' => ($returnCode === 0)
        ];
    }
}
