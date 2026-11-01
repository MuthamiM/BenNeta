<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;

class EmailController extends BaseController {
    public function getTodayEmails(Request $request): Response {
        // Always fetch fresh emails from live IMAP on every call.
        // The old behavior required refresh=1 which the frontend never sent,
        // causing the inbox to show stale/empty data.
        $skipFetch = ($request->input('skip_fetch') === '1');
        
        if (!$skipFetch) {
            $this->runner->runPython('gmail_client.py', [], 20);
        }

        try {
            // First try the gmail_client live fetch for real-time data
            $liveResult = $this->runner->runPython('gmail_client.py', [], 20);
            $liveData = json_decode($liveResult['output'], true);

            if ($liveData && isset($liveData['emails']) && !empty($liveData['emails'])) {
                return $this->success([
                    'count' => count($liveData['emails']),
                    'mailbox' => $liveData['account'] ?? 'musamwange2@gmail.com',
                    'emails' => $liveData['emails'],
                    'source' => 'live_imap'
                ]);
            }

            // Fallback to database if live fetch returns nothing
            $sql = "SELECT * FROM emails WHERE date(created_at) = date('now') ORDER BY id DESC LIMIT 50";
            $emails = $this->db->query($sql);

            // Fetch pending tasks extracted from emails
            $tasks = $this->db->query("SELECT * FROM pending_tasks ORDER BY id DESC LIMIT 50");
            $emailTasksMap = [];
            foreach ($tasks as $t) {
                $eid = $t['email_id'] ?? null;
                if ($eid) {
                    $emailTasksMap[$eid][] = $t;
                }
            }

            foreach ($emails as &$em) {
                $em['extracted_tasks'] = $emailTasksMap[$em['id']] ?? [];
            }

            return $this->success([
                'count' => count($emails),
                'mailbox' => $this->config->get('gmail_address', 'musamwange2@gmail.com'),
                'emails' => $emails,
                'source' => 'database'
            ]);
        } catch (\Exception $e) {
            return $this->error("Failed to query inbox: " . $e->getMessage(), 500);
        }
    }

    public function sendReply(Request $request): Response {
        $to = trim((string)$request->input('to'));
        $subject = trim((string)$request->input('subject'));
        $body = trim((string)$request->input('body'));
        $cc = trim((string)$request->input('cc', ''));
        $replyToId = trim((string)$request->input('reply_to_message_id', ''));

        if (empty($to)) {
            return $this->error("Recipient email address required", 400);
        }
        if (empty($subject)) {
            return $this->error("Subject line required", 400);
        }
        if (empty($body)) {
            return $this->error("Email body required", 400);
        }

        $args = ['send', $to, $subject, $body];
        if (!empty($cc)) {
            $args[] = $cc;
        } else {
            $args[] = '';
        }
        if (!empty($replyToId)) {
            $args[] = $replyToId;
        }

        $result = $this->runner->runPython('email_sender.py', $args, 20);
        $data = json_decode($result['output'], true);

        if (!$data) {
            return $this->error("Email send failed: could not parse response", 500, ['raw' => $result['output']]);
        }

        if (isset($data['status']) && $data['status'] === 'SENT') {
            return $this->success($data);
        }

        return $this->error($data['error'] ?? 'Unknown send failure', 500, $data);
    }

    public function getPendingReviews(Request $request): Response {
        try {
            $rows = $this->db->query("SELECT * FROM pending_tasks WHERE status = 'pending' ORDER BY id DESC");
            return $this->success(['pending_tasks' => $rows]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function approveEmail(Request $request): Response {
        $id = (int)$request->input('id');
        if (!$id) {
            return $this->error("Missing required task id", 400);
        }

        try {
            $tasks = $this->db->query("SELECT * FROM pending_tasks WHERE id = ?", [$id]);
            if (empty($tasks)) {
                return $this->error("Task not found", 404);
            }
            $task = $tasks[0];

            $this->db->execute("UPDATE pending_tasks SET status = 'approved' WHERE id = ?", [$id]);
            $this->db->execute("
                INSERT INTO daily_tasks (source_pending_id, title, notes, priority, due_date, status)
                VALUES (?, ?, ?, ?, ?, 'todo')
            ", [
                $id,
                $task['title'],
                $task['description'],
                $task['priority'] ?? 'MEDIUM',
                $task['due_hint'] ?? date('Y-m-d')
            ]);

            return $this->success(['message' => 'Task approved and added to Daily Tasks']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function rejectEmail(Request $request): Response {
        $id = (int)$request->input('id');
        if (!$id) {
            return $this->error("Missing required task id", 400);
        }

        try {
            $this->db->execute("UPDATE pending_tasks SET status = 'rejected' WHERE id = ?", [$id]);
            return $this->success(['message' => 'Task rejected']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
