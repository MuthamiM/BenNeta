<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use BenNeta\Services\PdfService;

class AssistantController extends BaseController {
    /**
     * Process natural language commands using the EXER Intelligence engine.
     */
    public function executeCommand(Request $request): Response {
        $prompt = trim((string)$request->input('prompt', ''));
        if (empty($prompt)) {
            $prompt = trim((string)$request->input('command', ''));
        }

        if (empty($prompt)) {
            return $this->error("Command prompt required", 400);
        }

        // Optional attached file / screenshot
        $attachment = trim((string)$request->input('attachment', ''));
        $attachmentPath = trim((string)$request->input('file_path', ''));
        if (empty($attachmentPath) && !empty($attachment)) {
            $attachmentPath = $attachment;
        }

        $cmdArgs = ['command', $prompt];
        if (!empty($attachmentPath)) {
            $cmdArgs[] = $attachmentPath;
        }

        $result = $this->runner->runPython('assistant_commander.py', $cmdArgs, 35);
        $data = json_decode($result['output'], true);

        if (!$data) {
            return $this->error("Command execution failed", 500, ['raw' => $result['output']]);
        }

        return $this->success($data);
    }

    /**
     * Request desktop permission confirmation to open an Excel file.
     */
    public function openExcel(Request $request): Response {
        $file = trim((string)$request->input('file', ''));
        if (empty($file)) {
            $file = trim((string)$request->input('path', ''));
        }

        if (empty($file)) {
            return $this->error("File name or path required", 400);
        }

        $result = $this->runner->runPython('assistant_commander.py', ['open', $file], 15);
        $data = json_decode($result['output'], true);

        if (!$data) {
            return $this->error("Failed to launch Excel opener", 500, ['raw' => $result['output']]);
        }

        if (isset($data['status']) && $data['status'] === 'SUCCESS') {
            return $this->success($data);
        }

        return $this->error($data['error'] ?? 'Failed to open file in desktop application', 500, $data);
    }

    /**
     * Direct download endpoint for generated spreadsheets.
     */
    public function downloadExcel(Request $request): Response {
        $filename = basename((string)$request->input('file', ''));
        if (empty($filename)) {
            return $this->error("File parameter required", 400);
        }

        $candidates = [
            dirname(dirname(__DIR__)) . '/data/spreadsheets/' . $filename,
            '/app/data/spreadsheets/' . $filename,
            '/home/cantroll/assistant/data/spreadsheets/' . $filename,
            '/home/cantroll/Downloads/' . $filename,
        ];

        $filePath = null;
        foreach ($candidates as $c) {
            if (file_exists($c) && is_file($c)) {
                $filePath = $c;
                break;
            }
        }

        if (!$filePath) {
            return $this->error("Spreadsheet file not found", 404);
        }

        $isXlsx = str_ends_with($filename, '.xlsx');
        $contentType = $isXlsx 
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=utf-8';

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($filePath);
        exit(0);
    }

    /**
     * Direct download / on-the-fly generation endpoint for PDF documents.
     * Supports both existing saved PDF files and dynamic generation from title/content.
     */
    public function downloadPdf(Request $request): Response {
        $filename = basename((string)$request->input('file', ''));
        $title = trim((string)$request->input('title', ''));
        $content = trim((string)$request->input('content', ''));

        // Case 1: Existing PDF file on disk
        if (!empty($filename)) {
            if (!str_ends_with($filename, '.pdf')) {
                $filename .= '.pdf';
            }

            $candidates = [
                '/app/data/documents/' . $filename,
                '/app/data/spreadsheets/' . $filename,
                dirname(dirname(__DIR__)) . '/data/documents/' . $filename,
                dirname(dirname(__DIR__)) . '/data/spreadsheets/' . $filename,
                '/home/cantroll/assistant/data/documents/' . $filename,
                '/tmp/' . $filename,
            ];

            $filePath = null;
            foreach ($candidates as $c) {
                if (file_exists($c) && is_file($c)) {
                    $filePath = $c;
                    break;
                }
            }

            if ($filePath) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($filePath));
                header('Cache-Control: no-cache, no-store, must-revalidate');
                readfile($filePath);
                exit(0);
            }
        }

        // Case 2: Dynamic PDF generation from title + content
        if (empty($title)) {
            $title = !empty($filename) ? str_replace(['.pdf', '_', '-'], [' ', ' ', ' '], $filename) : "EXER Intelligence Document";
        }
        if (empty($content)) {
            $content = "No content provided for document generation.";
        }

        $pdfBytes = PdfService::generatePdf($title, $content);
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $title);
        $safeName = trim($safeName, '_');
        if (empty($safeName)) {
            $safeName = "exer_document";
        }
        $outFilename = "EXER_{$safeName}.pdf";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $outFilename . '"');
        header('Content-Length: ' . strlen($pdfBytes));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $pdfBytes;
        exit(0);
    }

    /**
     * File & Screenshot Upload Endpoint.
     * Supports multipart/form-data upload as well as base64 screenshot pastes.
     */
    public function uploadFile(Request $request): Response {
        $uploadDir = '/app/data/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
        if (!is_dir($uploadDir)) {
            $uploadDir = dirname(dirname(__DIR__)) . '/data/uploads';
            @mkdir($uploadDir, 0777, true);
        }

        $originalName = '';
        $targetPath = '';
        $mimeType = 'application/octet-stream';
        $fileSize = 0;

        // 1. Multipart Form Upload
        if (!empty($_FILES['file']['name'])) {
            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return $this->error("File upload error code: " . $file['error'], 400);
            }

            $orig = basename($file['name']);
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
            $timestamp = date('Ymd_His');
            $uniqueName = "upload_{$timestamp}_{$safeBase}." . ($ext ?: 'bin');
            $targetPath = $uploadDir . '/' . $uniqueName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                return $this->error("Failed to store uploaded file", 500);
            }

            $originalName = $orig;
            $mimeType = $file['type'] ?: 'application/octet-stream';
            $fileSize = filesize($targetPath);
        }
        // 2. Base64 Upload (e.g. Screenshot Paste)
        else {
            $base64Data = (string)$request->input('image_base64', '');
            if (empty($base64Data)) {
                $base64Data = (string)$request->input('file_base64', '');
            }

            if (empty($base64Data)) {
                return $this->error("No file or image payload received", 400);
            }

            // Remove data URI scheme header if present
            $ext = 'png';
            $mimeType = 'image/png';
            if (preg_match('/^data:(image\/[a-zA-Z0-9\+\-]+);base64,/', $base64Data, $matches)) {
                $mimeType = $matches[1];
                $ext = explode('/', $mimeType)[1] ?? 'png';
                if ($ext === 'jpeg') $ext = 'jpg';
                $base64Data = substr($base64Data, strlen($matches[0]));
            }

            $decoded = base64_decode($base64Data);
            if ($decoded === false) {
                return $this->error("Invalid base64 payload", 400);
            }

            $timestamp = date('Ymd_His');
            $customName = trim((string)$request->input('file_name', ''));
            if ($customName) {
                $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($customName, PATHINFO_FILENAME));
                $uniqueName = "screenshot_{$timestamp}_{$safeBase}.{$ext}";
                $originalName = $customName;
            } else {
                $uniqueName = "screenshot_{$timestamp}.{$ext}";
                $originalName = "Screenshot_{$timestamp}.{$ext}";
            }

            $targetPath = $uploadDir . '/' . $uniqueName;
            file_put_contents($targetPath, $decoded);
            $fileSize = strlen($decoded);
        }

        $fileName = basename($targetPath);

        return $this->success([
            'file_name' => $originalName ?: $fileName,
            'stored_name' => $fileName,
            'file_path' => $targetPath,
            'file_url' => "/api.php?action=get_uploaded_file&file=" . urlencode($fileName),
            'mime_type' => $mimeType,
            'size_bytes' => $fileSize,
            'is_image' => str_starts_with($mimeType, 'image/'),
            'message' => 'File uploaded successfully'
        ]);
    }

    /**
     * Serve uploaded images and files for in-browser preview.
     */
    public function getUploadedFile(Request $request): Response {
        $filename = basename((string)$request->input('file', ''));
        if (empty($filename)) {
            return $this->error("File parameter required", 400);
        }

        $candidates = [
            '/app/data/uploads/' . $filename,
            dirname(dirname(__DIR__)) . '/data/uploads/' . $filename,
            '/home/cantroll/assistant/data/uploads/' . $filename,
            '/tmp/' . $filename,
        ];

        $filePath = null;
        foreach ($candidates as $c) {
            if (file_exists($c) && is_file($c)) {
                $filePath = $c;
                break;
            }
        }

        if (!$filePath) {
            return $this->error("Uploaded file not found", 404);
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            'json' => 'application/json',
        ];

        $mime = $mimes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=86400');
        readfile($filePath);
        exit(0);
    }
}
