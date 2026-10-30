# Assistant: Email Intelligence & Daily Task Manager

A polyglot personal assistant engine for PC (Linux and Windows) built with **Python**, **PHP**, and **Rust**.

The assistant connects to your live IMAP mailbox, monitors incoming emails, detects high-priority notifications received while notifications were turned off (quiet hours or Do Not Disturb), prompts you to allow/allocate them into daily tasks, and executes custom action rules when specific notifications appear.

Zero emojis. Pure performance, cross-platform compatibility, and clean terminal workflows.

---

## Architecture Overview

| Component | Language | Role |
| :--- | :--- | :--- |
| **Email & Intelligence Engine** | **Python 3** | Live IMAP connection, MIME parsing, NLP task/deadline extraction, quiet hours/DND tracking, interactive review prompter. |
| **Daily Tasks & Rules Manager** | **PHP 8** | High-speed daily task dashboard, rule management CLI, SQLite integration, and `todo.txt` synchronization. |
| **Background Daemon & Notifier** | **Rust** | Ultra-lightweight background monitoring loop (< 5 MB RAM, 0% idle CPU) and native desktop notification dispatcher for Linux and Windows. |

---

## Key Features

1. **Live Email Ingestion (No Simulation / Mocking)**
   - Connects directly to any live IMAP server (Gmail, Outlook, custom corporate mail servers) over SSL/TLS.
   - Parses subjects, MIME parts, and extracts actionable requests and deadlines.

2. **Missed Notifications Detection ("When Notifications Were Off")**
   - Automatically tracks quiet hours and Do Not Disturb (DND) modes.
   - Detects whether notifications arrived during off-hours and highlights them during review as missed alerts.

3. **Interactive Review: "Ask to Allow Them into Daily Tasks"**
   - Runs an interactive terminal approval queue (`./assistant.sh review`).
   - For every candidate task, lets you:
     - `[A]` Allow directly into your Daily Tasks schedule.
     - `[E]` Edit title, priority, or deadline before allowing.
     - `[D]` Dismiss and archive.
     - `[S]` Skip for later.

4. **Custom Notification Action Rules**
   - Choose what the assistant does when certain notifications appear:
     - `ask`: Queue for manual approval (standard).
     - `auto_approve`: Automatically add to Daily Tasks without asking.
     - `auto_approve_and_notify`: Add to Daily Tasks and trigger an immediate desktop notification.
     - `auto_dismiss`: Automatically ignore matching notifications.
     - `execute_command`: Run a custom shell command/script on Linux or Windows when matched.

5. **Cross-Platform PC Support**
   - Linux: Uses `assistant.sh`, `notify-send`, and POSIX paths.
   - Windows: Uses `assistant.bat`, PowerShell toast notifications, and Windows paths.
   - Dual sync with SQLite database (`data/assistant.db`) and plain-text `~/todo.txt`.

---

## Directory Structure

```
assistant/
├── assistant.sh             # Unified Linux CLI launcher
├── assistant.bat            # Unified Windows CLI launcher
├── .gitignore               # Ignores credentials, DB, and build artifacts
├── config/
│   ├── config.example.json  # Sanitized configuration template
│   └── config.json          # Local credentials (chmod 600, untracked)
├── data/
│   ├── assistant.db         # SQLite database
│   └── automation.log       # Rule execution audit log
├── python/
│   ├── db.py                # Database schema manager
│   ├── config_manager.py    # Cross-platform config reader
│   ├── setup_config.py      # Interactive IMAP setup wizard
│   ├── task_extractor.py    # NLP action item & deadline extractor
│   ├── notification_guard.py# Quiet hours & desktop notification dispatcher
│   ├── rule_engine.py       # Custom notification rules evaluator & executor
│   ├── email_fetcher.py     # Live IMAP fetcher
│   └── review_prompter.py   # Interactive review prompter
├── php/
│   ├── task_manager.php     # Daily tasks list, complete, add, dnd, sync
│   └── rules_manager.php    # Notification action rules list, add, remove
└── rust/
    ├── Cargo.toml           # Rust package configuration
    └── src/
        └── main.rs          # Cross-platform background daemon
```

---

## Getting Started

### 1. Prerequisites
- Linux or Windows PC
- Python 3.10+
- PHP 8.1+ with `pdo_sqlite`
- Rust 1.70+ with Cargo

### 2. Configure IMAP Mailbox
Run the interactive setup wizard to enter your IMAP credentials and test the live connection:

**Linux:**
```bash
./assistant.sh configure
```

**Windows:**
```cmd
assistant.bat configure
```

### 3. Scan Mailbox for Incoming Emails
Scan your live inbox for new emails and missed notifications:

```bash
./assistant.sh scan
```

### 4. Review and Allow Tasks
Walk through candidate tasks and allocate approved items to your daily schedule:

```bash
./assistant.sh review
```

### 5. Manage Daily Tasks
View and check off tasks from your daily task list:

```bash
./assistant.sh tasks
./assistant.sh done <id>
./assistant.sh add "Prepare server migration plan" HIGH "Friday"
```

### 6. Configure Custom Action Rules
View or add rules to define what the assistant does when specific notifications appear:

```bash
./assistant.sh rules list
./assistant.sh rules add
./assistant.sh rules remove <id>
```

### 7. Run Background Monitoring Daemon
Run the compiled Rust daemon for continuous low-resource monitoring:

```bash
./assistant.sh daemon
```

---

## License

MIT License.
