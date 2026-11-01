<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;

class DownloadController extends BaseController {
    private string $distDir;

    public function __construct() {
        parent::__construct();
        $this->distDir = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'dist';
    }

    public function detectOs(Request $request): Response {
        $ua = $request->getUserAgent();
        $os = 'linux';
        $osName = 'Linux (Debian / Ubuntu / Mint)';
        $recommendedPkg = 'deb';
        $recommendedFile = 'ezer_1.0.0_amd64.deb';

        if (stripos($ua, 'Windows') !== false || stripos($ua, 'Win64') !== false || stripos($ua, 'WOW64') !== false) {
            $os = 'windows';
            $osName = 'Windows 10 / 11 (64-bit)';
            $recommendedPkg = 'exe';
            $recommendedFile = 'ezer_windows_x64.zip';
        } elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) {
            $os = 'macos';
            $osName = 'macOS (Darwin)';
            $recommendedPkg = 'deb';
            $recommendedFile = 'ezer_1.0.0_amd64.deb';
        }

        return $this->success([
            'detected_os' => $os,
            'os_name' => $osName,
            'recommended_package' => $recommendedPkg,
            'recommended_file' => $recommendedFile,
            'user_agent' => $ua
        ]);
    }

    private function resolveDebPath(): ?string {
        $root = dirname(dirname(__DIR__));
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . 'ezer_1.0.0_amd64.deb',
            $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . 'benneta_1.0.0_amd64.deb',
            $this->distDir . DIRECTORY_SEPARATOR . 'ezer_1.0.0_amd64.deb',
            $this->distDir . DIRECTORY_SEPARATOR . 'benneta_1.0.0_amd64.deb',
            $root . DIRECTORY_SEPARATOR . 'ezer_1.0.0_amd64.deb',
            $root . DIRECTORY_SEPARATOR . 'benneta_1.0.0_amd64.deb'
        ];
        foreach ($candidates as $p) {
            if (file_exists($p)) return $p;
        }
        return null;
    }

    private function resolveWindowsPath(): ?string {
        $root = dirname(dirname(__DIR__));
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . 'ezer_windows_x64.zip',
            $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'downloads' . DIRECTORY_SEPARATOR . 'benneta_windows_x64.zip',
            $this->distDir . DIRECTORY_SEPARATOR . 'ezer_windows_x64.zip',
            $this->distDir . DIRECTORY_SEPARATOR . 'benneta_windows_x64.zip',
            $root . DIRECTORY_SEPARATOR . 'ezer_windows_x64.zip',
            $root . DIRECTORY_SEPARATOR . 'benneta_windows_x64.zip'
        ];
        foreach ($candidates as $p) {
            if (file_exists($p)) return $p;
        }
        return null;
    }

    public function getReleaseInfo(Request $request): Response {
        $debPath = $this->resolveDebPath();
        $winPath = $this->resolveWindowsPath();

        $debSize = $debPath ? filesize($debPath) : 0;
        $debSha = $debPath ? hash_file('sha256', $debPath) : 'pending_build';

        $winSize = $winPath ? filesize($winPath) : 0;
        $winSha = $winPath ? hash_file('sha256', $winPath) : 'pending_build';

        return $this->success([
            'version' => '1.0.0',
            'release_name' => 'EZER PC Intelligence & Automation',
            'memory_budget' => 'Strict 28MB RAM Constraint',
            'packages' => [
                'linux' => [
                    'format' => 'deb',
                    'filename' => 'ezer_1.0.0_amd64.deb',
                    'arch' => 'amd64',
                    'size_bytes' => $debSize,
                    'size_formatted' => round($debSize / 1024, 1) . ' KB',
                    'sha256' => $debSha,
                    'download_url' => '/api.php?action=download_deb'
                ],
                'windows' => [
                    'format' => 'exe_zip',
                    'filename' => 'ezer_windows_x64.zip',
                    'arch' => 'x64',
                    'size_bytes' => $winSize,
                    'size_formatted' => round($winSize / (1024 * 1024), 2) . ' MB',
                    'sha256' => $winSha,
                    'download_url' => '/api.php?action=download_windows'
                ]
            ]
        ]);
    }

    public function downloadDeb(Request $request): void {
        $debPath = $this->resolveDebPath();

        if (!$debPath) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ERROR', 'error' => 'Debian package not found. Run bash build_deb.sh']);
            exit;
        }

        header('Content-Type: application/vnd.debian.binary-package');
        header('Content-Disposition: attachment; filename="benneta_1.0.0_amd64.deb"');
        header('Content-Length: ' . filesize($debPath));
        header('Cache-Control: public, max-age=3600');
        readfile($debPath);
        exit;
    }

    public function downloadWindows(Request $request): void {
        $winPath = $this->resolveWindowsPath();
        if (!$winPath) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ERROR', 'error' => 'Windows package not found.']);
            exit;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="benneta_windows_x64.zip"');
        header('Content-Length: ' . filesize($winPath));
        header('Cache-Control: public, max-age=3600');
        readfile($winPath);
        exit;
    }
}
