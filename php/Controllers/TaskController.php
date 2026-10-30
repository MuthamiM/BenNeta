<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;

class TaskController extends BaseController {
    public function getAutomatedTasks(Request $request): Response {
        $result = $this->runner->runPython('tasks_engine.py', ['list'], 15);
        $tasksData = json_decode($result['output'], true);

        if (!$tasksData || !isset($tasksData['tasks'])) {
            return $this->error("Failed to list automated microservices tasks", 500, ['raw' => $result['output']]);
        }

        return $this->success($tasksData);
    }

    public function runTask(Request $request): Response {
        $taskKey = $request->input('task');
        if (empty($taskKey)) {
            return $this->error("Task key required", 400);
        }

        $result = $this->runner->runPython('tasks_engine.py', ['run', $taskKey], 30);
        $parsed = json_decode($result['output'], true);

        return $this->success([
            'task' => $taskKey,
            'result' => $parsed ?: ['status' => 'UNKNOWN', 'raw' => $result['output']],
            'exit_code' => $result['code']
        ]);
    }

    public function getDailyTasks(Request $request): Response {
        try {
            $rows = $this->db->query("SELECT * FROM daily_tasks ORDER BY status ASC, id DESC");
            return $this->success(['daily_tasks' => $rows]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function completeTask(Request $request): Response {
        $id = (int)$request->input('id');
        if (!$id) {
            return $this->error("Task id required", 400);
        }

        try {
            $this->db->execute("UPDATE daily_tasks SET status = 'done', completed_at = CURRENT_TIMESTAMP WHERE id = ?", [$id]);
            return $this->success(['message' => 'Task marked complete']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function addCustomTask(Request $request): Response {
        $title = trim((string)$request->input('title'));
        $priority = strtoupper(trim((string)$request->input('priority', 'MEDIUM')));
        $notes = trim((string)$request->input('notes', ''));

        if (empty($title)) {
            return $this->error("Task title cannot be empty", 400);
        }

        try {
            $this->db->execute("
                INSERT INTO daily_tasks (title, notes, priority, due_date, status)
                VALUES (?, ?, ?, date('now'), 'todo')
            ", [$title, $notes, $priority]);

            return $this->success(['message' => 'Task added to daily list']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
