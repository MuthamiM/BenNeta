"""
Live IMAP Email Fetcher Module.
Strictly connects to live IMAP server (no simulation, no mock).
Detects notifications and actionable tasks received during 'notifications off' periods.
Applies user-configured action rules (what the assistant does when a certain notification appears).
PC support for Linux and Windows with zero emojis.
"""

import sys
import imaplib
import email
from email.header import decode_header
from datetime import datetime
from email.utils import parsedate_to_datetime

from db import get_db_connection
from config_manager import load_config, is_configured
from task_extractor import extract_task_from_email
from notification_guard import get_current_notification_status, send_desktop_notification
from rule_engine import evaluate_rules_for_notification, process_action

def decode_mime_header(header_val):
    if not header_val:
        return ""
    decoded_fragments = decode_header(header_val)
    result = []
    for fragment, encoding in decoded_fragments:
        if isinstance(fragment, bytes):
            try:
                result.append(fragment.decode(encoding or "utf-8", errors="replace"))
            except Exception:
                result.append(fragment.decode("latin1", errors="replace"))
        else:
            result.append(str(fragment))
    return "".join(result)

def extract_email_body(msg):
    body = ""
    if msg.is_multipart():
        for part in msg.walk():
            ctype = part.get_content_type()
            cdisp = str(part.get("Content-Disposition", ""))
            if "attachment" not in cdisp:
                if ctype == "text/plain":
                    try:
                        payload = part.get_payload(decode=True)
                        if payload:
                            body = payload.decode(part.get_content_charset() or "utf-8", errors="replace")
                            break
                    except Exception:
                        pass
                elif ctype == "text/html" and not body:
                    try:
                        payload = part.get_payload(decode=True)
                        if payload:
                            body = payload.decode(part.get_content_charset() or "utf-8", errors="replace")
                    except Exception:
                        pass
    else:
        try:
            payload = msg.get_payload(decode=True)
            if payload:
                body = payload.decode(msg.get_content_charset() or "utf-8", errors="replace")
        except Exception:
            body = str(msg.get_payload())
    return body

def connect_imap(cfg):
    imap_cfg = cfg.get("imap", {})
    host = imap_cfg.get("host")
    port = int(imap_cfg.get("port", 993))
    use_ssl = imap_cfg.get("ssl", True)
    username = imap_cfg.get("username")
    password = imap_cfg.get("password")

    if not (host and username and password):
        raise ValueError("IMAP connection is not configured. Run './assistant.sh configure' first.")

    if use_ssl:
        client = imaplib.IMAP4_SSL(host, port, timeout=15)
    else:
        client = imaplib.IMAP4(host, port, timeout=15)

    client.login(username, password)
    return client

def scan_live_emails(limit=25, unseen_only=True):
    """
    Connects to live IMAP server, reads emails, identifies actionable alerts/tasks,
    evaluates user rules, and flags whether they arrived when notifications were off.
    """
    cfg = load_config()
    if not is_configured():
        print("[-] Mailbox not configured. Please run './assistant.sh configure' to enter live IMAP credentials.")
        return {
            "status": "unconfigured",
            "message": "Mailbox not configured."
        }

    conn = get_db_connection()
    c = conn.cursor()

    client = None
    scanned_count = 0
    new_tasks_count = 0
    auto_approved_count = 0
    missed_during_off_count = 0

    try:
        print(f"[*] Connecting to live IMAP mailbox: {cfg['imap']['host']}:{cfg['imap']['port']}...")
        client = connect_imap(cfg)
        folder = cfg.get("imap", {}).get("folder", "INBOX")
        client.select(folder, readonly=True)

        criterion = "UNSEEN" if unseen_only else "ALL"
        status, data = client.search(None, criterion)
        if status != "OK" or not data or not data[0]:
            if unseen_only:
                status, data = client.search(None, "ALL")

        msg_ids = data[0].split() if (data and data[0]) else []
        msg_ids = msg_ids[-limit:] if len(msg_ids) > limit else msg_ids
        msg_ids.reverse()

        print(f"[*] Inspecting {len(msg_ids)} recent messages from {folder}...")

        for mid in msg_ids:
            scanned_count += 1
            res, msg_data = client.fetch(mid, "(RFC822)")
            if res != "OK" or not msg_data or not msg_data[0]:
                continue

            raw_email = msg_data[0][1]
            parsed_msg = email.message_from_bytes(raw_email)

            subject = decode_mime_header(parsed_msg.get("Subject", "(No Subject)"))
            sender = decode_mime_header(parsed_msg.get("From", "(Unknown Sender)"))
            message_id = parsed_msg.get("Message-ID", f"imap-{mid.decode()}")
            date_header = parsed_msg.get("Date")

            try:
                date_sent = parsedate_to_datetime(date_header) if date_header else datetime.now()
            except Exception:
                date_sent = datetime.now()

            c.execute("SELECT id FROM emails WHERE msg_id = ?", (message_id,))
            if c.fetchone():
                continue

            is_off, off_reason = get_current_notification_status(date_sent)
            received_during_off = 1 if is_off else 0

            body = extract_email_body(parsed_msg)

            c.execute("""
                INSERT INTO emails (msg_id, imap_uid, sender, subject, date_sent, body_snippet, received_during_off)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            """, (
                message_id,
                int(mid.decode()) if mid.decode().isdigit() else None,
                sender,
                subject,
                date_sent.isoformat(),
                body[:300],
                received_during_off
            ))
            email_row_id = c.lastrowid

            # Extract actionable task candidate
            task_candidate = extract_task_from_email(subject, sender, body)
            if task_candidate:
                if received_during_off:
                    missed_during_off_count += 1

                # Evaluate user-defined notification action rules
                action_info = evaluate_rules_for_notification(subject, sender, body, task_candidate)
                outcome = process_action(action_info, email_row_id, task_candidate, received_during_off, conn)

                if "auto_approved" in outcome:
                    auto_approved_count += 1
                    print(f"[+] Rule matched: '{action_info['rule_name']}' -> Auto-approved into Daily Tasks: {task_candidate['title']}")
                elif outcome == "queued_for_review":
                    new_tasks_count += 1
                    print(f"[*] Candidate task queued for review: {task_candidate['title']}")
                else:
                    print(f"[*] Rule action outcome: {outcome} for {task_candidate['title']}")

        c.execute("UPDATE system_state SET value = ? WHERE key = 'last_sync_timestamp'", (datetime.now().isoformat(),))
        c.execute("INSERT INTO notification_logs (event_type, message) VALUES (?, ?)", 
                  ("sync", f"Scanned {scanned_count} live emails. Queued {new_tasks_count} for review, {auto_approved_count} auto-approved."))
        conn.commit()

        if new_tasks_count > 0:
            notice = f"{new_tasks_count} new task(s) awaiting approval"
            if missed_during_off_count > 0:
                notice += f" ({missed_during_off_count} arrived while notifications were OFF)"
            notice += ". Run './assistant.sh review' to approve."
            send_desktop_notification("Incoming Tasks Detected", notice, "high" if missed_during_off_count > 0 else "normal")

        print(f"[+] Live scan finished: {scanned_count} checked, {new_tasks_count} pending review, {auto_approved_count} auto-allocated ({missed_during_off_count} missed during off-hours).")

    except Exception as e:
        print(f"[-] IMAP scan failed: {e}")
        return {"status": "error", "error": str(e)}
    finally:
        if client:
            try:
                client.close()
                client.logout()
            except Exception:
                pass
        conn.close()

    return {
        "status": "success",
        "scanned": scanned_count,
        "pending_review": new_tasks_count,
        "auto_approved": auto_approved_count,
        "missed_during_off": missed_during_off_count
    }

if __name__ == "__main__":
    scan_live_emails()
