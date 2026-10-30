<?php
/**
 * Daily Task Manager & Notification Status in PHP for PC (Linux and Windows).
 * Interfaces with SQLite database to list, complete, and manage daily tasks.
 * Zero emojis.
 */

$projectRoot = dirname(__DIR__);
$dbPath = $projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'assistant.db';

$homeDir = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
$todoTxtPath = $homeDir . DIRECTORY_SEPARATOR . 'todo.txt';

define('DB_PATH', $dbPath);
define('TODO_TXT_PATH', $todoTxtPath);

function getDb(): PDO {
    if (!file_exists(DB_PATH)) {
        fwrite(STDERR, "[-] Database not found at " . DB_PATH . ". Run python3 python/db.py first.\n");
        exit(1);
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function color($text, $colorCode): string {
    // If on Windows and ANSI not supported in old cmd.exe, plain text fallback
    if (DIRECTORY_SEPARATOR === '\\' && !getenv('ANSICON') && !getenv('WT_SESSION')) {
        return $text;
    }
    return "\033[" . $colorCode . "m" . $text . "\033[0m";
}

function listDailyTasks(string $filter = 'all'): void {
    $pdo = getDb();
    $sql = "SELECT * FROM daily_tasks";
    if ($filter === 'todo') {
        $sql .= " WHERE status = 'todo'";
    } elseif ($filter === 'completed') {
        $sql .= " WHERE status = 'completed'";
    }
    $sql .= " ORDER BY CASE priority WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END, id ASC";

    $stmt = $pdo->query($sql);
    $tasks = $stmt->fetchAll();

    echo "\n" . color("================================================================================", "1;36") . "\n";
    echo color("                        DAILY TASKS DASHBOARD (PC)                              ", "1;37") . "\n";
    echo color("================================================================================", "1;36") . "\n";

    if (empty($tasks)) {
        echo "  [+] No daily tasks recorded yet.\n";
        echo "  Run './assistant.sh scan' to read emails, then './assistant.sh review' to approve tasks.\n";
        echo color("================================================================================\n\n", "1;36");
        return;
    }

    printf("%-5s | %-6s | %-8s | %-16s | %s\n", "ID", "STATE", "PRIORITY", "DUE DATE", "TITLE & NOTES");
    echo "------+--------+----------+------------------+--------------------------------------\n";

    foreach ($tasks as $t) {
        $box = ($t['status'] === 'completed') ? color("[x]", "1;32") : color("[ ]", "1;33");
        
        $prio = strtoupper($t['priority'] ?? 'MEDIUM');
        if ($prio === 'HIGH') {
            $prioStr = color("[HIGH]", "1;31");
        } elseif ($prio === 'MEDIUM') {
            $prioStr = color("[MED] ", "1;33");
        } else {
            $prioStr = color("[LOW] ", "1;32");
        }

        $due = $t['due_date'] ? substr($t['due_date'], 0, 16) : "-";
        $title = $t['title'];
        if ($t['status'] === 'completed') {
            $title = color($title, "90;9");
        }

        printf("%-5d | %-14s | %-16s | %-16s | %s\n", $t['id'], $box, $prioStr, $due, $title);
        if (!empty($t['notes'])) {
            echo "      |        |          |                  | " . color("-> " . $t['notes'], "90") . "\n";
        }
    }
    echo color("================================================================================\n\n", "1;36");
}

function completeTask(int $id): void {
    $pdo = getDb();
    $stmt = $pdo->prepare("UPDATE daily_tasks SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        echo color("[+] Task #$id marked as COMPLETED.\n", "1;32");
        syncWithTodoTxt();
    } else {
        echo color("[-] Task #$id not found.\n", "1;31");
    }
}

function addManualTask(string $title, string $priority = 'MEDIUM', string $due = ''): void {
    $pdo = getDb();
    $stmt = $pdo->prepare("INSERT INTO daily_tasks (title, priority, due_date, status, notes) VALUES (?, ?, ?, 'todo', 'Manual entry')");
    $stmt->execute([$title, strtoupper($priority), $due]);
    $newId = $pdo->lastInsertId();
    echo color("[+] Task #$newId added to Daily Tasks: '$title'\n", "1;32");
    syncWithTodoTxt();
}

function showPendingQueue(): void {
    $pdo = getDb();
    $stmt = $pdo->query("SELECT * FROM pending_tasks WHERE status = 'pending' ORDER BY is_missed_notification DESC, id ASC");
    $pending = $stmt->fetchAll();

    echo "\n" . color("==============================================================", "1;33") . "\n";
    echo color("             PENDING TASKS AWAITING YOUR APPROVAL             ", "1;37") . "\n";
    echo color("==============================================================", "1;33") . "\n";

    if (empty($pending)) {
        echo "  [+] No candidate tasks pending approval.\n\n";
        return;
    }

    foreach ($pending as $p) {
        $flag = $p['is_missed_notification'] ? color(" [!] MISSED DURING OFF-HOURS/DND", "1;31") : "";
        echo color("#" . $p['id'] . " ", "1;36") . color($p['title'], "1;37") . $flag . "\n";
        echo "   Priority: [" . $p['priority'] . "] | Due: " . ($p['due_hint'] ?: "Not specified") . "\n";
        echo "   Sender:   " . ($p['sender'] ?: "Unknown") . "\n\n";
    }
    echo "To review and allow these into your daily schedule, run: ./assistant.sh review\n\n";
}

function toggleDnd(string $action): void {
    $pdo = getDb();
    if ($action === 'status') {
        $stmt = $pdo->query("SELECT value, updated_at FROM system_state WHERE key = 'notifications_mode'");
        $row = $stmt->fetch();
        $mode = $row ? $row['value'] : 'ON';
        echo "\nNotifications Mode: ";
        if ($mode === 'OFF') {
            echo color("[OFF] (Do Not Disturb Active)", "1;31") . "\n";
            echo "Emails received now will be flagged as 'MISSED DURING OFF'.\n\n";
        } else {
            echo color("[ON] (Normal Active)", "1;32") . "\n\n";
        }
        return;
    }

    $newMode = strtoupper($action) === 'OFF' ? 'OFF' : 'ON';
    $stmt = $pdo->prepare("UPDATE system_state SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE key = 'notifications_mode'");
    $stmt->execute([$newMode]);

    echo color("[+] Notifications mode updated to: $newMode\n", $newMode === 'OFF' ? "1;31" : "1;32");
}

function syncWithTodoTxt(): void {
    if (!file_exists(dirname(TODO_TXT_PATH))) {
        return;
    }
    $pdo = getDb();
    $stmt = $pdo->query("SELECT * FROM daily_tasks WHERE status = 'todo' ORDER BY id ASC");
    $tasks = $stmt->fetchAll();

    $lines = [];
    foreach ($tasks as $t) {
        $p = strtoupper($t['priority'] ?? 'MEDIUM');
        $prefix = ($p === 'HIGH') ? "(A) " : (($p === 'MEDIUM') ? "(B) " : "");
        $lines[] = $prefix . $t['title'];
    }

    @file_put_contents(TODO_TXT_PATH, implode("\n", $lines) . "\n");
}

// CLI Routing
$cmd = $argv[1] ?? 'list';

switch ($cmd) {
    case 'list':
    case 'ls':
        listDailyTasks($argv[2] ?? 'all');
        break;
    case 'done':
    case 'complete':
        if (!isset($argv[2])) {
            fwrite(STDERR, "Usage: php task_manager.php complete <task_id>\n");
            exit(1);
        }
        completeTask((int)$argv[2]);
        break;
    case 'add':
        if (!isset($argv[2])) {
            fwrite(STDERR, "Usage: php task_manager.php add \"<title>\" [priority] [due]\n");
            exit(1);
        }
        addManualTask($argv[2], $argv[3] ?? 'MEDIUM', $argv[4] ?? '');
        break;
    case 'pending':
        showPendingQueue();
        break;
    case 'dnd':
        toggleDnd($argv[2] ?? 'status');
        break;
    case 'sync':
        syncWithTodoTxt();
        echo "[+] Synced with " . TODO_TXT_PATH . "\n";
        break;
    default:
        echo "Commands:\n";
        echo "  list [todo|completed|all]   List daily tasks\n";
        echo "  complete <id>               Mark task as finished\n";
        echo "  add <title> [prio] [due]    Add a new daily task\n";
        echo "  pending                     View tasks awaiting approval\n";
        echo "  dnd [on|off|status]         Check or toggle notification mode\n";
        echo "  sync                        Sync with todo.txt\n";
        break;
}
