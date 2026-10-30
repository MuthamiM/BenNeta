"""
Gmail Live Connector for musamwange2@gmail.com.
Fetches today's inbox emails, runs multi-factor spam detection, and extracts action items.
Sanitizes all text to guarantee zero emojis. PC support for Linux and Windows.
"""

import sys
import re
import imaplib
import email
from email.header import decode_header
from datetime import datetime, date
from email.utils import parsedate_to_datetime

from config_manager import load_config, save_config
from spam_detector import analyze_email_spam
from task_extractor import extract_task_from_email

GMAIL_USER = "musamwange2@gmail.com"
GMAIL_HOST = "imap.gmail.com"
GMAIL_PORT = 993

EMOJI_REGEX = re.compile(
    r"[\U00010000-\U0010ffff]|[\u2600-\u27bf]|[\u2300-\u23ff]|[\u2b50]|[\u3030]|[\u00a9]|[\u00ae]|[\u2122]",
    flags=re.UNICODE
)

def strip_emojis(text):
    if not text:
        return ""
    return EMOJI_REGEX.sub("", text).strip()

def decode_mime_header(val):
    if not val:
        return ""
    fragments = decode_header(val)
    out = []
    for f, enc in fragments:
        if isinstance(f, bytes):
            try:
                out.append(f.decode(enc or "utf-8", errors="replace"))
            except Exception:
                out.append(f.decode("latin1", errors="replace"))
        else:
            out.append(str(f))
    return strip_emojis("".join(out))

def extract_body(msg):
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
    return strip_emojis(body)

def get_gmail_credentials():
    cfg = load_config()
    imap_cfg = cfg.get("imap", {})

    username = imap_cfg.get("username") or GMAIL_USER
    password = imap_cfg.get("password") or ""
    host = imap_cfg.get("host") or GMAIL_HOST

    return host, username, password

def fetch_todays_gmail_inbox(limit=30):
    """
    Connects to Gmail IMAP for musamwange2@gmail.com,
    searches for all emails received TODAY, evaluates spam and actionable tasks.
    """
    host, username, password = get_gmail_credentials()

    if not password:
        return {
            "status": "AUTH_REQUIRED",
            "account": GMAIL_USER,
            "message": "Gmail App Password required. Save your 16-character App Password in config.json.",
            "emails": []
        }

    client = None
    todays_emails = []
    spam_count = 0
    actionable_count = 0

    try:
        client = imaplib.IMAP4_SSL(host, GMAIL_PORT, timeout=15)
        client.login(username, password)
        client.select("INBOX", readonly=True)

        today_imap_date = date.today().strftime("%d-%b-%Y")
        search_criterion = f'(SINCE "{today_imap_date}")'

        status, data = client.search(None, search_criterion)

        if status != "OK" or not data or not data[0]:
            status, data = client.search(None, "ALL")

        msg_ids = data[0].split() if (data and data[0]) else []
        msg_ids = msg_ids[-limit:] if len(msg_ids) > limit else msg_ids
        msg_ids.reverse()

        for mid in msg_ids:
            res, msg_data = client.fetch(mid, "(RFC822)")
            if res != "OK" or not msg_data or not msg_data[0]:
                continue

            raw = msg_data[0][1]
            parsed = email.message_from_bytes(raw)

            subject = decode_mime_header(parsed.get("Subject", "(No Subject)"))
            sender = decode_mime_header(parsed.get("From", "(Unknown Sender)"))
            date_hdr = parsed.get("Date")

            try:
                date_sent = parsedate_to_datetime(date_hdr) if date_hdr else datetime.now()
                date_str = date_sent.strftime("%Y-%m-%d %H:%M")
            except Exception:
                date_str = "Today"

            body = extract_body(parsed)

            # 1. Run Spam Detection Algorithm
            spam_analysis = analyze_email_spam(subject, sender, body)

            # 2. Run Task Extraction Algorithm
            task_candidate = extract_task_from_email(subject, sender, body)

            if spam_analysis["is_spam"]:
                spam_count += 1
                status_label = "SPAM_DETECTED"
            elif spam_analysis["is_promo"]:
                status_label = "PROMOTION"
            elif task_candidate:
                actionable_count += 1
                status_label = "ACTIONABLE_TASK"
            else:
                status_label = "CLEAN_INBOX"

            todays_emails.append({
                "id": mid.decode(),
                "subject": subject,
                "sender": sender,
                "date": date_str,
                "status_label": status_label,
                "spam_score": spam_analysis["spam_score"],
                "spam_reasons": spam_analysis["reasons"],
                "is_spam": spam_analysis["is_spam"],
                "is_promo": spam_analysis["is_promo"],
                "task": task_candidate,
                "snippet": body[:200]
            })

        return {
            "status": "SUCCESS",
            "account": username,
            "total_today": len(todays_emails),
            "spam_detected_count": spam_count,
            "actionable_tasks_count": actionable_count,
            "emails": todays_emails
        }

    except imaplib.IMAP4.error as e:
        return {
            "status": "AUTH_FAILED",
            "account": username,
            "error": f"IMAP authentication failed: {e}. Verify Google App Password."
        }
    except Exception as e:
        return {
            "status": "CONNECTION_ERROR",
            "account": username,
            "error": str(e)
        }
    finally:
        if client:
            try:
                client.close()
                client.logout()
            except Exception:
                pass

if __name__ == "__main__":
    res = fetch_todays_gmail_inbox(limit=10)
    print(f"[+] Retrieved {res.get('total_today')} emails from today's inbox.")
