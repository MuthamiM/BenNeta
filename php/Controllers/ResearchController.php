<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;

class ResearchController extends BaseController {
    public function search(Request $request): Response {
        $query = trim((string)$request->input('query'));
        if (empty($query)) {
            return $this->error("Search query required", 400);
        }

        $result = $this->runner->runPython('research_replier.py', ['search', $query], 25);
        $data = json_decode($result['output'], true);

        if (!$data) {
            return $this->error("Search execution failed", 500, ['raw' => $result['output']]);
        }

        return $this->success($data);
    }

    public function draftReply(Request $request): Response {
        $subject = trim((string)$request->input('subject', 'Follow-up'));
        $sender = trim((string)$request->input('sender', 'contact@mftechnologies.org'));
        $body = trim((string)$request->input('body', ''));

        $result = $this->runner->runPython('research_replier.py', ['draft', $subject, $sender, $body], 30);
        $data = json_decode($result['output'], true);

        if (!$data) {
            return $this->error("Draft generation failed", 500, ['raw' => $result['output']]);
        }

        return $this->success($data);
    }
}
