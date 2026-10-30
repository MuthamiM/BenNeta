#!/usr/bin/env bash
# Assistant CLI Entrypoint for Linux (PC)
# Polyglot Architecture: Python, PHP, Rust. Zero emojis.

set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

mkdir -p "$DIR/data"

# Auto-compile Rust daemon if not already compiled
if [ ! -f "$DIR/rust/target/release/assistant-daemon" ]; then
    echo "[*] Compiling lightweight Rust background daemon..."
    (cd "$DIR/rust" && cargo build --release -q)
    echo "[+] Rust daemon compiled successfully."
fi

# Ensure database is initialized
if [ ! -f "$DIR/data/assistant.db" ]; then
    python3 "$DIR/python/db.py" > /dev/null
fi

CMD="${1:-help}"

case "$CMD" in
    ui|web|dashboard)
        PORT="${2:-8765}"
        echo "[+] Starting Assistant Web Dashboard on http://localhost:$PORT ..."
        echo "[+] Press Ctrl+C to stop."
        php -S "0.0.0.0:$PORT" -t "$DIR/web"
        ;;
    configure|config|setup)
        python3 "$DIR/python/setup_config.py"
        ;;
    scan|fetch|check)
        python3 "$DIR/python/email_fetcher.py"
        ;;
    review|approve|queue)
        python3 "$DIR/python/review_prompter.py"
        ;;
    rules|rule)
        shift || true
        php "$DIR/php/rules_manager.php" "$@"
        ;;
    tasks|list|ls)
        shift || true
        php "$DIR/php/task_manager.php" list "$@"
        ;;
    done|complete)
        shift || true
        php "$DIR/php/task_manager.php" complete "$@"
        ;;
    add)
        shift || true
        php "$DIR/php/task_manager.php" add "$@"
        ;;
    pending)
        php "$DIR/php/task_manager.php" pending
        ;;
    dnd)
        shift || true
        php "$DIR/php/task_manager.php" dnd "$@"
        ;;
    daemon|watch|start)
        "$DIR/rust/target/release/assistant-daemon" run
        ;;
    sync)
        php "$DIR/php/task_manager.php" sync
        ;;
    notify)
        shift || true
        "$DIR/rust/target/release/assistant-daemon" notify "$@"
        ;;
    help|--help|-h|*)
        echo "============================================================"
        echo "   ASSISTANT: EMAIL & DAILY TASK MANAGER (PC: LINUX & WIN)  "
        echo "============================================================"
        echo "Usage: ./assistant.sh <command>"
        echo ""
        echo "Web Dashboard:"
        echo "  ui [port]              Start interactive Web Dashboard (default: 8765)"
        echo ""
        echo "Email & Notifications:"
        echo "  configure              Setup live IMAP mailbox credentials"
        echo "  scan                   Scan live mailbox for emails & missed alerts"
        echo "  review                 Ask to allow/dismiss candidate tasks into Daily Tasks"
        echo "  rules [list|add|rm]    Configure what assistant does on certain notifications"
        echo "  dnd [on|off|status]    Check or toggle Do Not Disturb (notifications off)"
        echo ""
        echo "Daily Tasks Management:"
        echo "  tasks [todo|completed] List daily tasks table"
        echo "  done <id>              Mark task as completed"
        echo "  add \"<title>\" [prio]   Manually add a task"
        echo "  sync                   Sync tasks with ~/todo.txt"
        echo ""
        echo "Background Engine:"
        echo "  daemon                 Run the low-resource Rust monitoring daemon"
        echo "  notify <title> <msg>   Send a native desktop notification"
        echo "============================================================"
        ;;
esac
