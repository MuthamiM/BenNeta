"""
Rule Engine Module for the Assistant.
Evaluates incoming notifications and emails against user-defined action rules.
Allows user to determine what the assistant does when certain notifications appear.
Zero emojis.
"""

import os
import subprocess
from datetime import datetime
from config_manager import load_config
from notification_guard import send_desktop_notification

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
AUTOMATION_LOG = os.path.join(PROJECT_ROOT, "data", "automation.log")

def match_rule(rule, subject, sender, body):
    subj_lower = (subject or "").lower()
    sender_lower = (sender or "").lower()
    body_lower = (body or "").lower()

    # Check subject keywords
    subj_keywords = rule.get("match_subject", [])
    if subj_keywords:
        matched_subj = any(kw.lower() in subj_lower or kw.lower() in body_lower for kw in subj_keywords)
        if not matched_subj:
            return False

    # Check sender keywords
    sender_keywords = rule.get("match_sender", [])
    if sender_keywords:
        matched_sender = any(kw.lower() in sender_lower for kw in sender_keywords)
        if not matched_sender:
            return False

    return True

def execute_custom_command(command_str, context):
    if not command_str:
        return
    try:
        os.makedirs(os.path.dirname(AUTOMATION_LOG), exist_ok=True)
        timestamp = datetime.now().strftime("[%Y-%m-%d %H:%M:%S]")
        
        # Execute command via shell (cross-platform Linux/Windows)
        res = subprocess.run(command_str, shell=True, capture_output=True, text=True, timeout=15)
        
        log_line = f"{timestamp} Rule Triggered Command: '{command_str}' | Exit: {res.returncode}\n"
        if res.stdout:
            log_line += f"  STDOUT: {res.stdout.strip()}\n"
        if res.stderr:
            log_line += f"  STDERR: {res.stderr.strip()}\n"

        with open(AUTOMATION_LOG, "a", encoding="utf-8") as f:
            f.write(log_line)

        print(f"[+] Automated action executed: {command_str} (Exit: {res.returncode})")
    except Exception as e:
        print(f"[-] Automated command failed: {e}")

def evaluate_rules_for_notification(subject, sender, body, task_candidate):
    """
    Finds the first matching rule and returns the action descriptor.
    """
    cfg = load_config()
    rules = cfg.get("rules", [])

    for rule in rules:
        if match_rule(rule, subject, sender, body):
            return {
                "matched": True,
                "rule_name": rule.get("name", "Custom Rule"),
                "action": rule.get("action", "ask"),
                "target_priority": rule.get("target_priority", task_candidate.get("priority", "MEDIUM") if task_candidate else "MEDIUM"),
                "command": rule.get("command", "")
            }

    # Default action is to ask the user
    return {
        "matched": False,
        "rule_name": "Default",
        "action": "ask",
        "target_priority": task_candidate.get("priority", "MEDIUM") if task_candidate else "MEDIUM",
        "command": ""
    }

def process_action(action_info, email_id, task_candidate, received_during_off, db_conn):
    """
    Executes the matched action on the database and system.
    Returns string summary of action taken.
    """
    action = action_info["action"]
    rule_name = action_info["rule_name"]
    priority = action_info["target_priority"]
    command = action_info.get("command")

    c = db_conn.cursor()

    if action in ("auto_approve", "auto_approve_and_notify"):
        # Auto-approve directly into daily_tasks table
        title = task_candidate["title"]
        notes = f"Auto-approved via rule '{rule_name}' from email: {task_candidate['sender']}"
        due_hint = task_candidate.get("due_hint", "")

        # 1. Insert into pending_tasks as approved
        c.execute("""
            INSERT INTO pending_tasks 
            (email_id, title, description, sender, priority, due_hint, is_missed_notification, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'approved')
        """, (
            email_id,
            title,
            task_candidate["description"],
            task_candidate["sender"],
            priority,
            due_hint,
            received_during_off
        ))
        pending_id = c.lastrowid

        # 2. Insert into daily_tasks
        c.execute("""
            INSERT INTO daily_tasks 
            (source_pending_id, title, notes, priority, due_date, status)
            VALUES (?, ?, ?, ?, ?, 'todo')
        """, (
            pending_id,
            title,
            notes,
            priority,
            due_hint
        ))

        # 3. Sync to todo.txt if configured
        cfg = load_config()
        todo_path = cfg.get("daemon", {}).get("todo_txt_path")
        if todo_path:
            try:
                p_flag = f"({priority[0]}) " if priority in ("HIGH", "MEDIUM") else ""
                with open(todo_path, "a", encoding="utf-8") as f:
                    f.write(f"{p_flag}{title}\n")
            except Exception:
                pass

        if action == "auto_approve_and_notify":
            send_desktop_notification("Task Auto-Allocated", f"Rule '{rule_name}' added: {title}", "high" if priority == "HIGH" else "normal")

        if command:
            execute_custom_command(command, {"title": title, "sender": task_candidate["sender"]})

        return f"auto_approved (Rule: {rule_name})"

    elif action == "auto_dismiss":
        c.execute("""
            INSERT INTO pending_tasks 
            (email_id, title, description, sender, priority, due_hint, is_missed_notification, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'dismissed')
        """, (
            email_id,
            task_candidate["title"],
            task_candidate["description"],
            task_candidate["sender"],
            priority,
            task_candidate.get("due_hint", ""),
            received_during_off
        ))
        if command:
            execute_custom_command(command, {"title": task_candidate["title"]})
        return f"auto_dismissed (Rule: {rule_name})"

    elif action == "notify_only":
        send_desktop_notification("Notification Alert", f"{task_candidate['title']} from {task_candidate['sender']}", "normal")
        if command:
            execute_custom_command(command, {"title": task_candidate["title"]})
        return f"notified_only (Rule: {rule_name})"

    elif action == "execute_command":
        if command:
            execute_custom_command(command, {"title": task_candidate["title"]})
        # Still queue as pending for user review
        c.execute("""
            INSERT INTO pending_tasks 
            (email_id, title, description, sender, priority, due_hint, is_missed_notification, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        """, (
            email_id,
            task_candidate["title"],
            task_candidate["description"],
            task_candidate["sender"],
            priority,
            task_candidate.get("due_hint", ""),
            received_during_off
        ))
        return f"executed_command_and_queued (Rule: {rule_name})"

    else:
        # Standard: queue for user review
        c.execute("""
            INSERT INTO pending_tasks 
            (email_id, title, description, sender, priority, due_hint, is_missed_notification, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        """, (
            email_id,
            task_candidate["title"],
            task_candidate["description"],
            task_candidate["sender"],
            priority,
            task_candidate.get("due_hint", ""),
            received_during_off
        ))
        return "queued_for_review"
