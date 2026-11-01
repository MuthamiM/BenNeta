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
        $taskKey = $request->input('task') ?: $request->input('task_key') ?: $request->input('task_id');
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
            $current = $this->db->query("SELECT status FROM daily_tasks WHERE id = ?", [$id]);
            $isCurrentlyDone = isset($current[0]['status']) && in_array(strtolower($current[0]['status']), ['done', 'completed']);
            $newStatus = $isCurrentlyDone ? 'todo' : 'done';

            $this->db->execute("
                UPDATE daily_tasks 
                SET status = ?, 
                    completed_at = CASE WHEN ? = 'done' THEN CURRENT_TIMESTAMP ELSE NULL END 
                WHERE id = ?
            ", [$newStatus, $newStatus, $id]);

            return $this->success([
                'message' => "Task marked as {$newStatus}",
                'status' => $newStatus
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function addCustomTask(Request $request): Response {
        $title = trim((string)$request->input('title'));
        $priority = strtoupper(trim((string)$request->input('priority', 'MEDIUM')));
        $category = trim((string)$request->input('category', 'General'));
        $dueDate = trim((string)$request->input('due_date', ''));
        $dueTime = trim((string)$request->input('due_time', ''));
        $actionType = trim((string)$request->input('action_type', 'manual'));
        $command = trim((string)$request->input('command', ''));
        $tags = trim((string)$request->input('tags', ''));
        $rawNotes = trim((string)$request->input('notes', ''));
        $runImmediate = $request->input('run_immediate');

        if (empty($title)) {
            return $this->error("Task title cannot be empty", 400);
        }

        // Format due timestamp
        $formattedDue = $dueDate;
        if (!empty($dueTime)) {
            $formattedDue = trim($formattedDue . ' ' . $dueTime);
        }
        if (empty($formattedDue)) {
            $formattedDue = date('Y-m-d H:i');
        }

        // Build rich structured details
        $notesDetails = [];
        if (!empty($category) && $category !== 'General') {
            $notesDetails[] = "Category: {$category}";
        }
        if (!empty($actionType) && $actionType !== 'manual') {
            $notesDetails[] = "Action: {$actionType}";
        }
        if (!empty($command)) {
            $notesDetails[] = "Target: {$command}";
        }
        if (!empty($tags)) {
            $notesDetails[] = "Tags: {$tags}";
        }
        if (!empty($rawNotes)) {
            $notesDetails[] = $rawNotes;
        }
        $combinedNotes = implode(' • ', $notesDetails);

        try {
            $this->db->execute("
                INSERT INTO daily_tasks (title, notes, priority, due_date, status)
                VALUES (?, ?, ?, ?, 'todo')
            ", [$title, $combinedNotes, $priority, $formattedDue]);

            // Trigger automation if requested
            $automationTriggered = false;
            $shouldRun = ($actionType === 'microservice' && !empty($command)) && 
                         ($runImmediate === null || $runImmediate === true || $runImmediate === '1' || $runImmediate === 'true');
            if ($shouldRun) {
                $this->runner->runPython('tasks_engine.py', ['run', $command], 20);
                $automationTriggered = true;
            }

            return $this->success([
                'message' => 'Task created with extended options',
                'automation_triggered' => $automationTriggered
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function deleteTask(Request $request): Response {
        $id = (int)$request->input('id');
        if (!$id) {
            return $this->error("Task ID required", 400);
        }

        try {
            $this->db->execute("DELETE FROM daily_tasks WHERE id = ?", [$id]);
            return $this->success(['deleted' => $id, 'message' => "Task {$id} deleted successfully."]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function clearCompletedTasks(Request $request): Response {
        try {
            $deleted = $this->db->execute("DELETE FROM daily_tasks WHERE status IN ('done', 'completed')");
            return $this->success(['cleared' => $deleted, 'message' => "Cleared {$deleted} completed tasks."]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}

