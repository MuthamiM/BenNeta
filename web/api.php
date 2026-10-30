<?php
/**
 * REST API for Assistant Web Dashboard, Security Microservices, & Gmail Connector.
 * Zero emojis. PC support for Linux and Windows.
 */

header('Content-Type: application/json');

$projectRoot = dirname(__DIR__);
$dbPath = $projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'assistant.db';
$configPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.json';
$homeDir = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
$todoPath = $homeDir . DIRECTORY_SEPARATOR . 'todo.txt';

require_once __DIR__ . '/security_middleware.php';

function getDb(): PDO {
    global $dbPath, $projectRoot;
    if (!file_exists($dbPath)) {
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

$pdo = getDb();
$cfg = loadConfig();

// -------------------------------------------------------------
// EXECUTE API SECURITY MICROSERVICE MIDDLEWARE
// -------------------------------------------------------------
$securityGuard = new ApiSecurityGuard($pdo, $cfg);
$securityGuard->inspectRequest();

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    switch ($action) {
        case 'status':
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

            $securityThreats = $pdo->query("SELECT COUNT(*) as c FROM security_events WHERE severity IN ('HIGH', 'CRITICAL')")->fetch()['c'] ?? 0;
            $blockedIps = $pdo->query("SELECT COUNT(*) as c FROM security_ip_reputation WHERE is_blocked = 1")->fetch()['c'] ?? 0;

            $currentUser = "Musa";
            $currentEmail = "musamwange2@gmail.com";
            if (!empty($imap['username'])) {
                $currentEmail = $imap['username'];
                $currentUser = ucfirst(explode('@', $imap['username'])[0]);
            }

            echo json_encode([
                "success" => true,
                "configured" => $isConfigured,
                "user_name" => $currentUser,
                "user_email" => $currentEmail,
                "host" => $imap['host'] ?? 'imap.gmail.com',
                "username" => $currentEmail,
                "folder" => $imap['folder'] ?? 'INBOX',
                "notifications_mode" => $dndMode,
                "last_sync" => $lastSync,
                "pending_count" => (int)$pendingCount,
                "missed_count" => (int)$missedCount,
                "todo_count" => (int)$tasksCount,
                "completed_count" => (int)$completedCount,
                "security_threats" => (int)$securityThreats,
                "blocked_ips" => (int)$blockedIps
            ]);
            break;

        case 'get_gmail_today':
            $pythonCmd = (DIRECTORY_SEPARATOR === '\\') ? "python" : "python3";
            $script = $projectRoot . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'gmail_client.py';
            $output = shell_exec("$pythonCmd \"$script\" 2>&1");
            
            $jsonStart = strpos($output, '{');
            $data = null;
            if ($jsonStart !== false) {
                $jsonStr = substr($output, $jsonStart);
                $data = json_decode($jsonStr, true);
            }
            echo json_encode(["success" => true, "data" => $data ?: ["status" => "NOTICE", "message" => $output]]);
            break;

        case 'get_security_metrics':
            $pythonCmd = (DIRECTORY_SEPARATOR === '\\') ? "python" : "python3";
            $script = $projectRoot . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'security_engine.py';
            $output = shell_exec("$pythonCmd \"$script\" 2>&1");

            $jsonStart = strpos($output, 'Current Security Telemetry:');
            $metrics = [];
            if ($jsonStart !== false) {
                $jsonPart = substr($output, $jsonStart + 27);
                $metrics = json_decode(trim($jsonPart), true);
            }
            echo json_encode(["success" => true, "data" => $metrics ?: ["error" => $output]]);
            break;

        case 'simulate_security_test':
            $testType = $input['test_type'] ?? 'path_traversal';
            if ($testType === 'sql_injection') {
                $securityGuard->logSecurityEvent("sql_injection", "CRITICAL", "Manual test: UNION SELECT probe triggered", true);
            } else {
                $securityGuard->logSecurityEvent("path_traversal", "CRITICAL", "Manual test: ../etc/passwd probe triggered", true);
            }
            echo json_encode(["success" => true, "message" => "Security probe logged and blocked by microservice."]);
            break;

        case 'save_gmail_credentials':
            $username = trim($input['username'] ?? 'musamwange2@gmail.com');
            $password = trim($input['password'] ?? '');
            
            $cfg['imap']['host'] = "imap.gmail.com";
            $cfg['imap']['port'] = 993;
            $cfg['imap']['ssl'] = true;
            $cfg['imap']['username'] = $username;
            if ($password) {
                $cfg['imap']['password'] = $password;
            }
            $cfg['imap']['folder'] = "INBOX";
            saveConfig($cfg);
            echo json_encode(["success" => true, "message" => "Gmail credentials saved. Connecting to imap.gmail.com..."]);
            break;

        case 'get_automated_tasks':
            $tasksList = [
                ["key" => "email_triage", "name" => "Live Email Inbox Triage", "desc" => "Scan live Gmail/IMAP inbox for unread emails and extract action items", "category" => "Email"],
                ["key" => "api_health", "name" => "API & Server Health Monitor", "desc" => "Ping live HTTP endpoints (mftechnologies, icapsq), verify response codes and latency", "category" => "Monitoring"],
                ["key" => "system_resources", "name" => "System Resource Guardian", "desc" => "Audit live CPU utilization %, memory free/used, and root disk space", "category" => "System"],
                ["key" => "calendar_sync", "name" => "Calendar & Agenda Synchronizer", "desc" => "Pull upcoming meetings from Google Calendar / iCal feed", "category" => "Schedule"],
                ["key" => "ssl_check", "name" => "SSL Certificate Expiry Checker", "desc" => "Audit domain TLS handshake and calculate days before certificate expires", "category" => "Security"],
                ["key" => "port_audit", "name" => "Open Ports & Listener Audit", "desc" => "Scan active local TCP listening ports and associated processes", "category" => "Network"],
                ["key" => "log_audit", "name" => "Disk & Log File Auditor", "desc" => "Scan log directories for oversized log files (>5MB)", "category" => "Maintenance"],
                ["key" => "process_watchdog", "name" => "Process Watchdog", "desc" => "Verify critical processes (python, php, cloudflared, nginx) are alive", "category" => "System"],
                ["key" => "daily_report", "name" => "Daily Executive Standup Briefing", "desc" => "Generate markdown summary of active tasks and infrastructure health", "category" => "Reporting"],
                ["key" => "email_alert", "name" => "Email Alert Dispatcher", "desc" => "Test live outbound SMTP alert transmission to configured recipient", "category" => "Alerts"]
            ];
            echo json_encode(["success" => true, "data" => $tasksList]);
            break;

        case 'run_automated_task':
            $taskKey = preg_replace('/[^a-zA-Z0-9_]/', '', $input['task_key'] ?? '');
            if (!$taskKey) {
                echo json_encode(["success" => false, "error" => "task_key required"]);
                break;
            }
            $pythonCmd = (DIRECTORY_SEPARATOR === '\\') ? "python" : "python3";
            $script = $projectRoot . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'tasks_engine.py';
            $output = shell_exec("$pythonCmd \"$script\" $taskKey 2>&1");
            
            $jsonStart = strpos($output, '{');
            $parsed = null;
            if ($jsonStart !== false) {
                $jsonStr = substr($output, $jsonStart);
                $parsed = json_decode($jsonStr, true);
            }

            echo json_encode([
                "success" => true,
                "raw_output" => $output,
                "result" => $parsed ?: ["message" => $output]
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

        default:
            echo json_encode(["success" => false, "error" => "Invalid action"]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
