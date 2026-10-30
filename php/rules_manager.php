<?php
/**
 * Notification Action Rules Manager in PHP for PC (Linux and Windows).
 * Lets user inspect, add, and remove rules governing what the assistant does
 * when specific notifications or emails appear.
 * Zero emojis.
 */

$projectRoot = dirname(__DIR__);
$configPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.json';
define('CONFIG_PATH', $configPath);

function loadConfig(): array {
    if (!file_exists(CONFIG_PATH)) {
        fwrite(STDERR, "[-] Configuration file not found at " . CONFIG_PATH . "\n");
        exit(1);
    }
    $data = json_decode(file_get_contents(CONFIG_PATH), true);
    if (!is_array($data)) {
        fwrite(STDERR, "[-] Invalid JSON in " . CONFIG_PATH . "\n");
        exit(1);
    }
    if (!isset($data['rules']) || !is_array($data['rules'])) {
        $data['rules'] = [];
    }
    return $data;
}

function saveConfig(array $data): void {
    file_put_contents(CONFIG_PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function listRules(): void {
    $config = loadConfig();
    $rules = $config['rules'] ?? [];

    echo "\n================================================================================\n";
    echo "                   NOTIFICATION ACTION RULES (WHAT ASSISTANT DOES)              \n";
    echo "================================================================================\n";

    if (empty($rules)) {
        echo "  [+] No custom rules configured yet. Standard action: [Ask to allow].\n";
        echo "  To add a rule, run: ./assistant.sh rules add\n";
        echo "================================================================================\n\n";
        return;
    }

    printf("%-4s | %-28s | %-22s | %-10s | %s\n", "ID", "RULE NAME", "ACTION", "PRIORITY", "KEYWORDS (SUBJECT / SENDER)");
    echo "-----+------------------------------+------------------------+------------+--------------------------------------\n";

    foreach ($rules as $r) {
        $id = $r['id'] ?? '-';
        $name = substr($r['name'] ?? 'Untitled', 0, 28);
        $action = $r['action'] ?? 'ask';
        $priority = $r['target_priority'] ?? 'MEDIUM';
        
        $subjWords = implode(', ', $r['match_subject'] ?? []);
        $senderWords = implode(', ', $r['match_sender'] ?? []);
        $kw = "";
        if ($subjWords) $kw .= "Subj: [" . $subjWords . "] ";
        if ($senderWords) $kw .= "From: [" . $senderWords . "]";
        if (!$kw) $kw = "(matches all)";

        printf("%-4s | %-28s | %-22s | %-10s | %s\n", $id, $name, $action, $priority, $kw);
        if (!empty($r['command'])) {
            echo "     |                              |                        |            | -> Shell Cmd: " . $r['command'] . "\n";
        }
    }
    echo "================================================================================\n";
    echo "Actions: ask, auto_approve, auto_approve_and_notify, auto_dismiss, execute_command\n\n";
}

function addRuleInteractive(): void {
    $config = loadConfig();
    $rules = $config['rules'] ?? [];

    echo "\n--- ADD NOTIFICATION ACTION RULE ---\n";
    echo "Define what the assistant does when a matching notification appears.\n\n";

    echo "Rule Name (e.g., 'Server Outage Alert'): ";
    $name = trim(fgets(STDIN));
    if (!$name) {
        echo "[-] Rule name cannot be empty.\n";
        return;
    }

    echo "Subject keywords to match (comma-separated, or press Enter for any): ";
    $subjInput = trim(fgets(STDIN));
    $subjKeywords = $subjInput ? array_map('trim', explode(',', $subjInput)) : [];

    echo "Sender keywords to match (comma-separated, or press Enter for any): ";
    $senderInput = trim(fgets(STDIN));
    $senderKeywords = $senderInput ? array_map('trim', explode(',', $senderInput)) : [];

    echo "\nChoose what the assistant should do when this notification appears:\n";
    echo "  1) Ask to allow (queue for manual approval)\n";
    echo "  2) Auto-approve directly into Daily Tasks\n";
    echo "  3) Auto-approve into Daily Tasks AND send desktop alert\n";
    echo "  4) Auto-dismiss (ignore and archive)\n";
    echo "  5) Execute a custom shell command or script\n";
    echo "Select option [1-5]: ";
    $opt = trim(fgets(STDIN));

    $action = "ask";
    if ($opt === '2') $action = "auto_approve";
    elseif ($opt === '3') $action = "auto_approve_and_notify";
    elseif ($opt === '4') $action = "auto_dismiss";
    elseif ($opt === '5') $action = "execute_command";

    echo "Target Task Priority (HIGH / MEDIUM / LOW) [MEDIUM]: ";
    $prio = strtoupper(trim(fgets(STDIN)));
    if (!in_array($prio, ['HIGH', 'MEDIUM', 'LOW'])) {
        $prio = 'MEDIUM';
    }

    $command = "";
    if ($action === 'execute_command' || $opt === '2' || $opt === '3') {
        echo "Optional shell command to execute on match (or press Enter for none): ";
        $command = trim(fgets(STDIN));
    }

    // Determine new ID
    $maxId = 0;
    foreach ($rules as $r) {
        if (isset($r['id']) && $r['id'] > $maxId) {
            $maxId = (int)$r['id'];
        }
    }
    $newId = $maxId + 1;

    $newRule = [
        "id" => $newId,
        "name" => $name,
        "match_subject" => array_values(array_filter($subjKeywords)),
        "match_sender" => array_values(array_filter($senderKeywords)),
        "action" => $action,
        "target_priority" => $prio,
        "command" => $command
    ];

    $config['rules'][] = $newRule;
    saveConfig($config);

    echo "\n[+] Rule #$newId ('$name') created successfully.\n";
    echo "    Action: $action | Priority: $prio\n\n";
}

function removeRule(int $id): void {
    $config = loadConfig();
    $rules = $config['rules'] ?? [];
    $filtered = [];
    $found = false;

    foreach ($rules as $r) {
        if (isset($r['id']) && (int)$r['id'] === $id) {
            $found = true;
            continue;
        }
        $filtered[] = $r;
    }

    if ($found) {
        $config['rules'] = array_values($filtered);
        saveConfig($config);
        echo "[+] Rule #$id removed.\n";
    } else {
        echo "[-] Rule #$id not found.\n";
    }
}

// CLI Routing
$cmd = $argv[1] ?? 'list';

switch ($cmd) {
    case 'list':
    case 'ls':
        listRules();
        break;
    case 'add':
        addRuleInteractive();
        break;
    case 'remove':
    case 'rm':
    case 'delete':
        if (!isset($argv[2])) {
            fwrite(STDERR, "Usage: php rules_manager.php remove <rule_id>\n");
            exit(1);
        }
        removeRule((int)$argv[2]);
        break;
    default:
        echo "Commands:\n";
        echo "  list               List all notification action rules\n";
        echo "  add                Add a new notification action rule\n";
        echo "  remove <rule_id>   Delete a rule by ID\n";
        break;
}
