#!/usr/bin/env python3
"""
Interactive Review and Approval Prompter for PC (Linux and Windows).
Presents detected emails and notifications and prompts the user to allow
or dismiss them into daily-to-daily tasks. No emojis.
"""

import sys
import os
from db import get_db_connection
from config_manager import load_config

C_RESET = "\033[0m"
C_BOLD = "\033[1m"
C_RED = "\033[91m"
C_GREEN = "\033[92m"
C_YELLOW = "\033[93m"
C_BLUE = "\033[94m"
C_CYAN = "\033[96m"

def format_priority(priority):
    p = (priority or "MEDIUM").upper()
    if p == "HIGH":
        return f"{C_RED}[HIGH]{C_RESET}"
    elif p == "MEDIUM":
        return f"{C_YELLOW}[MED]{C_RESET}"
    return f"{C_GREEN}[LOW]{C_RESET}"

def sync_task_to_todo_txt(title, priority):
    cfg = load_config()
    todo_path = cfg.get("daemon", {}).get("todo_txt_path")
    if not todo_path:
        return
    
    try:
        priority_flag = f"({priority[0]}) " if priority in ("HIGH", "MEDIUM") else ""
        entry = f"{priority_flag}{title}\n"
        with open(todo_path, "a", encoding="utf-8") as f:
            f.write(entry)
    except Exception as e:
        print(f"[-] Note: Could not append to {todo_path}: {e}")

def run_review_session():
    conn = get_db_connection()
    c = conn.cursor()

    c.execute("""
        SELECT pt.*, e.subject, e.date_sent 
        FROM pending_tasks pt
        LEFT JOIN emails e ON pt.email_id = e.id
        WHERE pt.status = 'pending'
        ORDER BY pt.priority = 'HIGH' DESC, pt.created_at ASC
    """)
    pending = c.fetchall()

    if not pending:
        print(f"\n{C_GREEN}[+] All caught up. No candidate tasks pending review.{C_RESET}\n")
        conn.close()
        return

    missed_count = sum(1 for row in pending if row["is_missed_notification"])
    print("\n" + "=" * 70)
    print(f"{C_BOLD}{C_CYAN}  ASSISTANT - TASK REVIEW & APPROVAL QUEUE{C_RESET}")
    print(f"  {len(pending)} item(s) pending approval.")
    if missed_count > 0:
        print(f"  {C_RED}[!] {missed_count} notification(s) arrived while notifications were OFF / DND.{C_RESET}")
    print("=" * 70)

    allocated_count = 0
    dismissed_count = 0

    for idx, row in enumerate(pending, 1):
        print(f"\n{C_BOLD}------------------- [ Item {idx} of {len(pending)} ] -------------------{C_RESET}")
        
        if row["is_missed_notification"]:
            print(f"{C_RED}[!] DETECTED WHILE NOTIFICATIONS WERE OFF / QUIET HOURS{C_RESET}")
        
        print(f"Sender:       {row['sender'] or 'Unknown'}")
        print(f"Subject:      {row['subject'] or '(No Subject)'}")
        print(f"Proposed:     {C_CYAN}{C_BOLD}{row['title']}{C_RESET}")
        print(f"Priority:     {format_priority(row['priority'])}")
        print(f"Due Date:     {row['due_hint'] or 'None'}")
        if row["description"]:
            print(f"Context:      {row['description']}")
        print("-" * 55)

        while True:
            prompt = (f"Action: [{C_GREEN}A{C_RESET}]llow to Daily Tasks | "
                      f"[{C_YELLOW}E{C_RESET}]dit & Allow | "
                      f"[{C_RED}D{C_RESET}]ismiss | "
                      f"[{C_BLUE}S{C_RESET}]kip | "
                      f"[Q]uit: ")
            choice = input(prompt).strip().lower()

            if choice in ("a", ""):
                c.execute("""
                    INSERT INTO daily_tasks (source_pending_id, title, notes, priority, due_date, status)
                    VALUES (?, ?, ?, ?, ?, 'todo')
                """, (
                    row["id"],
                    row["title"],
                    f"From email: {row['subject']} ({row['sender']})",
                    row["priority"],
                    row["due_hint"]
                ))
                c.execute("UPDATE pending_tasks SET status = 'approved' WHERE id = ?", (row["id"],))
                conn.commit()
                sync_task_to_todo_txt(row["title"], row["priority"])
                print(f"{C_GREEN}[+] Task allowed and added to Daily Tasks.{C_RESET}")
                allocated_count += 1
                break

            elif choice == "e":
                new_title = input(f"Task title [{row['title']}]: ").strip() or row["title"]
                new_priority = input(f"Priority (HIGH/MEDIUM/LOW) [{row['priority']}]: ").strip().upper() or row["priority"]
                if new_priority not in ("HIGH", "MEDIUM", "LOW"):
                    new_priority = row["priority"]
                new_due = input(f"Due date/time [{row['due_hint']}]: ").strip() or row["due_hint"]

                c.execute("""
                    INSERT INTO daily_tasks (source_pending_id, title, notes, priority, due_date, status)
                    VALUES (?, ?, ?, ?, ?, 'todo')
                """, (
                    row["id"],
                    new_title,
                    f"From email: {row['subject']} ({row['sender']})",
                    new_priority,
                    new_due
                ))
                c.execute("UPDATE pending_tasks SET status = 'approved', title = ?, priority = ?, due_hint = ? WHERE id = ?", 
                          (new_title, new_priority, new_due, row["id"]))
                conn.commit()
                sync_task_to_todo_txt(new_title, new_priority)
                print(f"{C_GREEN}[+] Task updated and added to Daily Tasks.{C_RESET}")
                allocated_count += 1
                break

            elif choice == "d":
                c.execute("UPDATE pending_tasks SET status = 'dismissed' WHERE id = ?", (row["id"],))
                conn.commit()
                print(f"{C_RED}[-] Dismissed.{C_RESET}")
                dismissed_count += 1
                break

            elif choice == "s":
                print(f"{C_BLUE}[*] Skipped for next session.{C_RESET}")
                break

            elif choice == "q":
                print("\n[+] Exiting review session.")
                conn.close()
                return
            else:
                print("[-] Invalid choice. Enter A, E, D, S, or Q.")

    conn.close()
    print("\n" + "=" * 70)
    print(f"[+] Review session completed: {allocated_count} allocated to Daily Tasks, {dismissed_count} dismissed.")
    print("=" * 70 + "\n")

if __name__ == "__main__":
    run_review_session()
