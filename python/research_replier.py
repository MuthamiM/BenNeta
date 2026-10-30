"""
Brave Search & Smart Email Research and Reply Engine for BenNeta.
Performs real-time web research via Brave Search API, maintains watched email threads,
and generates context-rich response drafts.
Strict constraint: Zero emojis across all output.
"""

import os
import sys
import json
import re
import sqlite3
import urllib.request
import urllib.parse
from datetime import datetime

DB_PATH = os.path.join(os.path.dirname(__file__), "..", "data", "assistant.db")
CONFIG_PATH = os.path.join(os.path.dirname(__file__), "..", "config", "config.json")

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

def get_brave_api_key():
    cfg = load_config()
    brave_cfg = cfg.get("brave_search", {})
    return brave_cfg.get("api_key") or "BSAjBNX5rbRBn4mViwSvBFA2GseOYya"

def init_db():
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    cur.execute("""
        CREATE TABLE IF NOT EXISTS watched_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pattern TEXT NOT NULL,
            pattern_type TEXT NOT NULL DEFAULT 'sender', -- sender, keyword, domain
            notes TEXT,
            auto_reply_enabled INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    """)
    cur.execute("""
        CREATE TABLE IF NOT EXISTS email_drafts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_id TEXT,
            recipient TEXT NOT NULL,
            subject TEXT NOT NULL,
            research_summary TEXT,
            draft_body TEXT NOT NULL,
            status TEXT DEFAULT 'DRAFT', -- DRAFT, SENT, ARCHIVED
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    """)
    conn.commit()
    conn.close()

def brave_search(query, count=5):
    """
    Execute real-time web search via Brave Search API.
    """
    api_key = get_brave_api_key()
    if not api_key:
        return {"error": "Brave Search API key not configured", "results": []}

    encoded_query = urllib.parse.quote(query.strip())
    url = f"https://api.search.brave.com/res/v1/web/search?q={encoded_query}&count={count}"

    req = urllib.request.Request(url, headers={
        "Accept": "application/json",
        "Accept-Encoding": "gzip",
        "X-Subscription-Token": api_key,
        "User-Agent": "BenNeta-Assistant/1.0"
    })

    try:
        with urllib.request.urlopen(req, timeout=12) as resp:
            content = resp.read()
            import gzip
            try:
                content = gzip.decompress(content)
            except Exception:
                pass
            data = json.loads(content.decode("utf-8", errors="replace"))
            web_results = data.get("web", {}).get("results", [])
            
            clean_results = []
            for item in web_results:
                clean_results.append({
                    "title": strip_emojis(item.get("title", "")),
                    "url": item.get("url", ""),
                    "description": strip_emojis(item.get("description", "")),
                    "page_age": item.get("page_age", "")
                })
            
            return {
                "success": True,
                "query": query,
                "total": len(clean_results),
                "results": clean_results
            }
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "query": query,
            "results": []
        }

def research_email(subject, sender, body_snippet=""):
    """
    Analyze email details, formulate queries, and query Brave Search for relevant intelligence.
    """
    clean_subj = strip_emojis(subject)
    clean_sender = strip_emojis(sender)
    clean_body = strip_emojis(body_snippet)

    # Extract sender organization or domain
    sender_domain = ""
    if "@" in clean_sender:
        sender_domain = clean_sender.split("@")[-1].replace(">", "").strip()

    # Formulate targeted queries
    queries = []
    # Strip common email prefixes like Fwd:, Re:
    normalized_subj = re.sub(r"^(Re|Fwd|Fw):\s*", "", clean_subj, flags=re.IGNORECASE).strip()
    
    if normalized_subj:
        queries.append(normalized_subj)
    if sender_domain and sender_domain not in ["gmail.com", "yahoo.com", "outlook.com", "hotmail.com"]:
        queries.append(f"{sender_domain} company profile")
    
    # Extract any URLs, domain names, or error codes from body
    urls = re.findall(r"https?://[^\s]+", clean_body)
    if urls:
        queries.append(f"{urls[0]} info")

    all_findings = []
    for q in queries[:2]:
        res = brave_search(q, count=3)
        if res.get("success") and res.get("results"):
            all_findings.extend(res["results"])

    # Deduplicate by URL
    seen_urls = set()
    unique_findings = []
    for f in all_findings:
        if f["url"] not in seen_urls:
            seen_urls.add(f["url"])
            unique_findings.append(f)

    return {
        "subject": clean_subj,
        "sender": clean_sender,
        "queries_run": queries[:2],
        "findings": unique_findings[:5]
    }

def generate_smart_reply(subject, sender, body_snippet="", user_instructions=""):
    """
    Construct a structured, professional reply draft backed by Brave research and email context.
    """
    research = research_email(subject, sender, body_snippet)
    findings = research.get("findings", [])

    clean_subj = strip_emojis(subject)
    clean_sender = strip_emojis(sender)
    
    # Extract greeting name
    name_match = re.search(r"^([^<@]+)", clean_sender)
    recipient_name = name_match.group(1).strip() if name_match else "there"
    if "@" in recipient_name:
        recipient_name = recipient_name.split("@")[0].capitalize()

    reply_subj = clean_subj if clean_subj.lower().startswith("re:") else f"Re: {clean_subj}"

    # Build response body
    paragraphs = []
    paragraphs.append(f"Hello {recipient_name},")
    paragraphs.append("Thank you for reaching out and following up on this.")

    # Contextual synthesis based on topic
    subj_lower = clean_subj.lower()
    if any(k in subj_lower for k in ["outage", "down", "error", "alert", "incident", "server"]):
        paragraphs.append("Our infrastructure monitoring team has flagged this notification. We are currently verifying active API endpoints, server processes, and logs.")
        if findings:
            paragraphs.append(f"Related reference: {findings[0]['title']} ({findings[0]['url']})")
        paragraphs.append("We will update you as soon as root cause analysis and mitigation steps are confirmed.")
    elif any(k in subj_lower for k in ["meeting", "invitation", "schedule", "call", "zoom", "calendar"]):
        paragraphs.append("I have received the calendar invitation and agenda details. I am reviewing the schedule against current commitments and will confirm availability shortly.")
    elif any(k in subj_lower for k in ["proposal", "quote", "invoice", "pricing", "contract"]):
        paragraphs.append("I have noted the details provided. We are reviewing the deliverables, specifications, and commercial terms.")
        if findings:
            paragraphs.append(f"Background intelligence noted: {findings[0]['description']}")
        paragraphs.append("I will revert with specific feedback or necessary approvals.")
    elif any(k in subj_lower for k in ["security", "access", "verification", "code", "password", "sign-in"]):
        paragraphs.append("The security sentinel has registered this access verification request. System integrity checks and IP reputation verification remain active.")
    else:
        paragraphs.append("I have reviewed the information in your message and am looking into the details.")
        if findings:
            top = findings[0]
            paragraphs.append(f"Relevant context identified: {top['title']} - {top['description'][:140]}...")

    if user_instructions:
        paragraphs.append(f"Note: {strip_emojis(user_instructions)}")

    paragraphs.append("Best regards,\nMusa Mwange\nBenNeta Security & Automation")

    draft_text = "\n\n".join(paragraphs)

    # Save draft in database
    init_db()
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    cur.execute("""
        INSERT INTO email_drafts (email_id, recipient, subject, research_summary, draft_body, status)
        VALUES (?, ?, ?, ?, ?, 'DRAFT')
    """, (
        "",
        clean_sender,
        reply_subj,
        json.dumps(findings),
        draft_text
    ))
    draft_id = cur.lastrowid
    conn.commit()
    conn.close()

    return {
        "draft_id": draft_id,
        "recipient": clean_sender,
        "subject": reply_subj,
        "draft_body": draft_text,
        "research": findings
    }

def add_watched_email(pattern, pattern_type="sender", notes=""):
    init_db()
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    cur.execute("""
        INSERT INTO watched_emails (pattern, pattern_type, notes)
        VALUES (?, ?, ?)
    """, (strip_emojis(pattern), pattern_type, strip_emojis(notes)))
    row_id = cur.lastrowid
    conn.commit()
    conn.close()
    return {"id": row_id, "pattern": pattern, "pattern_type": pattern_type}

def get_watched_emails():
    init_db()
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    cur.execute("SELECT * FROM watched_emails ORDER BY id DESC")
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows

def remove_watched_email(watched_id):
    init_db()
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    cur.execute("DELETE FROM watched_emails WHERE id = ?", (watched_id,))
    conn.commit()
    conn.close()
    return {"success": True}

if __name__ == "__main__":
    init_db()
    if len(sys.argv) > 1:
        cmd = sys.argv[1]
        if cmd == "search" and len(sys.argv) > 2:
            query = " ".join(sys.argv[2:])
            print(json.dumps(brave_search(query, count=3), indent=2))
        elif cmd == "research" and len(sys.argv) > 3:
            subj = sys.argv[2]
            sender = sys.argv[3]
            body = sys.argv[4] if len(sys.argv) > 4 else ""
            print(json.dumps(research_email(subj, sender, body), indent=2))
        elif cmd == "draft" and len(sys.argv) > 3:
            subj = sys.argv[2]
            sender = sys.argv[3]
            body = sys.argv[4] if len(sys.argv) > 4 else ""
            print(json.dumps(generate_smart_reply(subj, sender, body), indent=2))
        elif cmd == "list_watched":
            print(json.dumps(get_watched_emails(), indent=2))
        else:
            print(json.dumps({"error": f"Unknown command: {cmd}"}))
    else:
        # Default test run
        test = brave_search("M+F Technologies Germany", count=2)
        print(json.dumps(test, indent=2))
