"""
Gmail SMTP Email Sender for BenNeta.
Sends emails via smtp.gmail.com using the configured App Password.
Supports plain text and HTML bodies, CC, and reply threading (In-Reply-To / References).
Strict constraint: Zero emojis across all output.
"""

import sys
import os
import json
import re
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from datetime import datetime

CONFIG_PATH = os.path.join(os.path.dirname(__file__), "..", "config", "config.json")

SMTP_HOST = "smtp.gmail.com"
SMTP_PORT = 587

EMOJI_REGEX = re.compile(
    r"[\U00010000-\U0010ffff]|[\u2600-\u27bf]|[\u2300-\u23ff]|[\u2b50]|[\u3030]|[\u00a9]|[\u00ae]|[\u2122]",
    flags=re.UNICODE
)

def strip_emojis(text):
    if not text:
        return ""
    return EMOJI_REGEX.sub("", str(text)).strip()


def load_config():
    if os.path.exists(CONFIG_PATH):
        try:
            with open(CONFIG_PATH, "r", encoding="utf-8") as fh:
                return json.load(fh)
        except Exception:
            pass
    return {}


def get_smtp_credentials():
    """Retrieve SMTP credentials from the IMAP config (same App Password works for both)."""
    cfg = load_config()
    imap_cfg = cfg.get("imap", {})
    username = imap_cfg.get("username", "")
    password = imap_cfg.get("password", "")
    return username, password


def send_email(to, subject, body, cc=None, reply_to_message_id=None, html_body=None, sender_name="M&F Technologies"):
    """
    Send an email via Gmail SMTP.

    Args:
        to: Recipient email address (string or list of strings)
        subject: Email subject line
        body: Plain text body
        cc: Optional CC addresses (string or list of strings)
        reply_to_message_id: Optional Message-ID for threading replies
        html_body: Optional HTML body (sends multipart/alternative if provided)
        sender_name: Display name for the sender

    Returns:
        dict with status, message_id, and details
    """
    username, password = get_smtp_credentials()

    if not username or not password:
        return {
            "status": "AUTH_REQUIRED",
            "error": "Gmail SMTP credentials not configured. Set username and password in config.json imap section."
        }

    # Normalize recipients
    if isinstance(to, str):
        to = [addr.strip() for addr in to.split(",") if addr.strip()]
    if isinstance(cc, str):
        cc = [addr.strip() for addr in cc.split(",") if addr.strip()]
    elif cc is None:
        cc = []

    # Sanitize inputs
    clean_subject = strip_emojis(subject)
    clean_body = strip_emojis(body)
    from_addr = f"{strip_emojis(sender_name)} <{username}>"

    # Build the email message
    if html_body:
        msg = MIMEMultipart("alternative")
        msg.attach(MIMEText(clean_body, "plain", "utf-8"))
        msg.attach(MIMEText(html_body, "html", "utf-8"))
    else:
        msg = MIMEText(clean_body, "plain", "utf-8")

    msg["From"] = from_addr
    msg["To"] = ", ".join(to)
    msg["Subject"] = clean_subject
    msg["Date"] = datetime.now().strftime("%a, %d %b %Y %H:%M:%S %z")

    if cc:
        msg["Cc"] = ", ".join(cc)

    # Threading headers for reply chains
    if reply_to_message_id:
        msg["In-Reply-To"] = reply_to_message_id
        msg["References"] = reply_to_message_id

    all_recipients = to + cc

    try:
        server = smtplib.SMTP(SMTP_HOST, SMTP_PORT, timeout=15)
        server.ehlo()
        server.starttls()
        server.ehlo()
        server.login(username, password)
        server.sendmail(username, all_recipients, msg.as_string())
        server.quit()

        return {
            "status": "SENT",
            "to": to,
            "cc": cc,
            "subject": clean_subject,
            "timestamp": datetime.now().isoformat(),
            "message": f"Email sent successfully to {', '.join(to)}"
        }

    except smtplib.SMTPAuthenticationError as e:
        return {
            "status": "AUTH_FAILED",
            "error": f"SMTP authentication failed: {e}. Verify your Gmail App Password."
        }
    except smtplib.SMTPRecipientsRefused as e:
        return {
            "status": "RECIPIENT_REFUSED",
            "error": f"Recipient address rejected: {e}"
        }
    except Exception as e:
        return {
            "status": "SEND_FAILED",
            "error": f"Failed to send email: {e}"
        }


if __name__ == "__main__":
    if len(sys.argv) < 4:
        print(json.dumps({
            "error": "Usage: email_sender.py send <to> <subject> [body]",
            "usage": "python3 email_sender.py send recipient@example.com 'Subject Line' 'Body text'"
        }))
        sys.exit(1)

    cmd = sys.argv[1]
    if cmd == "send":
        to_addr = sys.argv[2]
        subj = sys.argv[3]
        body_text = sys.argv[4] if len(sys.argv) > 4 else ""
        cc_addr = sys.argv[5] if len(sys.argv) > 5 else None
        reply_id = sys.argv[6] if len(sys.argv) > 6 else None

        result = send_email(
            to=to_addr,
            subject=subj,
            body=body_text,
            cc=cc_addr,
            reply_to_message_id=reply_id
        )
        print(json.dumps(result))
    else:
        print(json.dumps({"error": f"Unknown command: {cmd}"}))
