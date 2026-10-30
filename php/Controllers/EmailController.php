<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;

class EmailController extends BaseController {
    public function getTodayEmails(Request $request): Response {
        $refresh = ($request->input('refresh') === '1' || $request->input('force') === '1');
        
        if ($refresh) {
            $this->runner->runPython('email_fetcher.py', ['fetch_today'], 20);
        }

        try {
            $today = date('Y-m-d');
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
                'emails' => $emails
            ]);
        } catch (\Exception $e) {
            return $this->error("Failed to query inbox: " . $e->getMessage(), 500);
        }
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
