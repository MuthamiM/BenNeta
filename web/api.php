<?php
/**
 * REST API for Assistant Web Dashboard.
 * Interacts with SQLite, config.json, and executes Python/Rust operations.
 * Zero emojis. PC support for Linux and Windows.
 */

header('Content-Type: application/json');

$projectRoot = dirname(__DIR__);
$dbPath = $projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'assistant.db';
$configPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.json';
$homeDir = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
$todoPath = $homeDir . DIRECTORY_SEPARATOR . 'todo.txt';

function getDb(): PDO {
    global $dbPath;
    if (!file_exists($dbPath)) {
        $projectRoot = dirname(__DIR__);
        $cmd = (DIRECTORY_SEPARATOR === '\\') ? "python \"$projectRoot\\python\\db.py\"" : "python3 \"$projectRoot/python/db.py\"";
        shell_exec($cmd);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function loadConfig(): array {
    global $configPath;
    if (!file_exists($configPath)) {
        return [];
    }
    return json_decode(file_get_contents($configPath), true) ?: [];
}

function saveConfig(array $data): void {
    global $configPath;
    file_put_contents($configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function syncTodoTxt(): void {
    global $todoPath;
    try {
        $pdo = getDb();
        $stmt = $pdo->query("SELECT * FROM daily_tasks WHERE status = 'todo' ORDER BY id ASC");
        $tasks = $stmt->fetchAll();
        $lines = [];
        foreach ($tasks as $t) {
            $p = strtoupper($t['priority'] ?? 'MEDIUM');
            $pfx = ($p === 'HIGH') ? "(A) " : (($p === 'MEDIUM') ? "(B) " : "");
            $lines[] = $pfx . $t['title'];
        }
        @file_put_contents($todoPath, implode("\n", $lines) . "\n");
    } catch (Exception $e) {}
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    $pdo = getDb();

    switch ($action) {
        case 'status':
            $cfg = loadConfig();
            $imap = $cfg['imap'] ?? [];
            $isConfigured = !empty($imap['host']) && !empty($imap['username']) && !empty($imap['password']);

            $dndStmt = $pdo->query("SELECT value FROM system_state WHERE key = 'notifications_mode'");
            $dndRow = $dndStmt->fetch();
            $dndMode = $dndRow ? $dndRow['value'] : 'ON';

            $syncStmt = $pdo->query("SELECT value FROM system_state WHERE key = 'last_sync_timestamp'");
            $syncRow = $syncStmt->fetch();
            $lastSync = $syncRow ? $syncRow['value'] : 'Never';

            $pendingCount = $pdo->query("SELECT COUNT(*) as c FROM pending_tasks WHERE status = 'pending'")->fetch()['c'] ?? 0;
            $tasksCount = $pdo->query("SELECT COUNT(*) as c FROM daily_tasks WHERE status = 'todo'")->fetch()['c'] ?? 0;
            $completedCount = $pdo->query("SELECT COUNT(*) as c FROM daily_tasks WHERE status = 'completed'")->fetch()['c'] ?? 0;
            $missedCount = $pdo->query("SELECT COUNT(*) as c FROM pending_tasks WHERE status = 'pending' AND is_missed_notification = 1")->fetch()['c'] ?? 0;

            echo json_encode([
                "success" => true,
                "configured" => $isConfigured,
                "host" => $imap['host'] ?? '',
                "username" => $imap['username'] ?? '',
                "folder" => $imap['folder'] ?? 'INBOX',
                "notifications_mode" => $dndMode,
                "last_sync" => $lastSync,
                "pending_count" => (int)$pendingCount,
                "missed_count" => (int)$missedCount,
                "todo_count" => (int)$tasksCount,
                "completed_count" => (int)$completedCount
            ]);
            break;

        case 'get_pending':
            $stmt = $pdo->query("
                SELECT pt.*, e.subject, e.date_sent, e.sender as email_sender
                FROM pending_tasks pt
                LEFT JOIN emails e ON pt.email_id = e.id
                WHERE pt.status = 'pending'
                ORDER BY pt.is_missed_notification DESC, pt.priority = 'HIGH' DESC, pt.id DESC
            ");
            echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);
            break;

        case 'get_tasks':
            $stmt = $pdo->query("
                SELECT * FROM daily_tasks 
                ORDER BY CASE status WHEN 'todo' THEN 1 ELSE 2 END,
                         CASE priority WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END, 
                         id DESC
            ");
            echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);
            break;

        case 'get_rules':
            $cfg = loadConfig();
            echo json_encode(["success" => true, "data" => $cfg['rules'] ?? []]);
            break;

        case 'scan':
            $pythonCmd = (DIRECTORY_SEPARATOR === '\\') ? "python" : "python3";
            $script = $projectRoot . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'email_fetcher.py';
            $output = shell_exec("$pythonCmd \"$script\" 2>&1");
            echo json_encode(["success" => true, "output" => $output ?: "Scan completed."]);
            break;

        case 'approve_task':
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM pending_tasks WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            if (!$item) {
                echo json_encode(["success" => false, "error" => "Task not found"]);
                break;
            }

            $title = $input['title'] ?? $item['title'];
            $priority = $input['priority'] ?? $item['priority'];
            $due = $input['due_hint'] ?? $item['due_hint'];

            $ins = $pdo->prepare("INSERT INTO daily_tasks (source_pending_id, title, notes, priority, due_date, status) VALUES (?, ?, ?, ?, ?, 'todo')");
            $ins->execute([$id, $title, "From email: " . $item['sender'], $priority, $due]);

            $upd = $pdo->prepare("UPDATE pending_tasks SET status = 'approved', title = ?, priority = ?, due_hint = ? WHERE id = ?");
            $upd->execute([$title, $priority, $due, $id]);

            syncTodoTxt();
            echo json_encode(["success" => true, "message" => "Task allocated to Daily Tasks."]);
            break;

        case 'dismiss_task':
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE pending_tasks SET status = 'dismissed' WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(["success" => true, "message" => "Task dismissed."]);
            break;

        case 'toggle_task':
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT status FROM daily_tasks WHERE id = ?");
            $stmt->execute([$id]);
            $t = $stmt->fetch();
            if (!$t) {
                echo json_encode(["success" => false, "error" => "Task not found"]);
                break;
            }
            $newStatus = ($t['status'] === 'completed') ? 'todo' : 'completed';
            $upd = $pdo->prepare("UPDATE daily_tasks SET status = ?, completed_at = CASE WHEN ? = 'completed' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = ?");
            $upd->execute([$newStatus, $newStatus, $id]);

            syncTodoTxt();
            echo json_encode(["success" => true, "new_status" => $newStatus]);
            break;

        case 'add_task':
            $title = trim($input['title'] ?? '');
            $priority = strtoupper(trim($input['priority'] ?? 'MEDIUM'));
            $due = trim($input['due_date'] ?? '');
            if (!$title) {
                echo json_encode(["success" => false, "error" => "Title is required"]);
                break;
            }
            if (!in_array($priority, ['HIGH', 'MEDIUM', 'LOW'])) $priority = 'MEDIUM';

            $ins = $pdo->prepare("INSERT INTO daily_tasks (title, priority, due_date, status, notes) VALUES (?, ?, ?, 'todo', 'Manual entry')");
            $ins->execute([$title, $priority, $due]);
            syncTodoTxt();
            echo json_encode(["success" => true, "message" => "Daily task added."]);
            break;

        case 'toggle_dnd':
            $mode = strtoupper($input['mode'] ?? 'ON');
            if (!in_array($mode, ['ON', 'OFF'])) $mode = 'ON';
            $upd = $pdo->prepare("UPDATE system_state SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE key = 'notifications_mode'");
            $upd->execute([$mode]);
            echo json_encode(["success" => true, "mode" => $mode]);
            break;

        case 'save_imap':
            $cfg = loadConfig();
            $cfg['imap']['host'] = trim($input['host'] ?? '');
            $cfg['imap']['port'] = (int)($input['port'] ?? 993);
            $cfg['imap']['ssl'] = !empty($input['ssl']);
            $cfg['imap']['username'] = trim($input['username'] ?? '');
            if (!empty($input['password'])) {
                $cfg['imap']['password'] = trim($input['password']);
            }
            $cfg['imap']['folder'] = trim($input['folder'] ?? 'INBOX') ?: 'INBOX';
            saveConfig($cfg);
            echo json_encode(["success" => true, "message" => "Configuration saved."]);
            break;

        case 'add_rule':
            $cfg = loadConfig();
            $rules = $cfg['rules'] ?? [];
            $maxId = 0;
            foreach ($rules as $r) {
                if (isset($r['id']) && (int)$r['id'] > $maxId) $maxId = (int)$r['id'];
            }
            $subjKws = array_values(array_filter(array_map('trim', explode(',', $input['match_subject'] ?? ''))));
            $senderKws = array_values(array_filter(array_map('trim', explode(',', $input['match_sender'] ?? ''))));

            $newRule = [
                "id" => $maxId + 1,
                "name" => trim($input['name'] ?? 'Custom Rule'),
                "match_subject" => $subjKws,
                "match_sender" => $senderKws,
                "action" => $input['rule_action'] ?? 'ask',
                "target_priority" => strtoupper($input['target_priority'] ?? 'MEDIUM'),
                "command" => trim($input['command'] ?? '')
            ];
            $cfg['rules'][] = $newRule;
            saveConfig($cfg);
            echo json_encode(["success" => true, "message" => "Rule created."]);
            break;

        case 'delete_rule':
            $id = (int)($input['id'] ?? 0);
            $cfg = loadConfig();
            $rules = $cfg['rules'] ?? [];
            $filtered = [];
            foreach ($rules as $r) {
                if (isset($r['id']) && (int)$r['id'] === $id) continue;
                $filtered[] = $r;
            }
            $cfg['rules'] = array_values($filtered);
            saveConfig($cfg);
            echo json_encode(["success" => true, "message" => "Rule removed."]);
            break;

        default:
            echo json_encode(["success" => false, "error" => "Invalid action"]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
