"""
Database schema and connection manager for the Assistant.
Includes tables for emails, daily tasks, notification rules, and security microservice telemetry.
Cross-platform support for Linux and Windows with zero emojis.
"""

import os
import sqlite3

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB_PATH = os.path.join(PROJECT_ROOT, "data", "assistant.db")

def get_db_connection():
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn

def init_db():
    conn = get_db_connection()
    cursor = conn.cursor()

    cursor.executescript("""
    CREATE TABLE IF NOT EXISTS system_state (
        key TEXT PRIMARY KEY,
        value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        msg_id TEXT UNIQUE,
        imap_uid INTEGER,
        sender TEXT,
        subject TEXT,
        date_sent TEXT,
        body_snippet TEXT,
        received_during_off INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS pending_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email_id INTEGER,
        title TEXT NOT NULL,
        description TEXT,
        sender TEXT,
        priority TEXT DEFAULT 'MEDIUM',
        due_hint TEXT,
        is_missed_notification INTEGER DEFAULT 0,
        status TEXT DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (email_id) REFERENCES emails(id)
    );

    CREATE TABLE IF NOT EXISTS daily_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_pending_id INTEGER,
        title TEXT NOT NULL,
        notes TEXT,
        priority TEXT DEFAULT 'MEDIUM',
        due_date TEXT,
        status TEXT DEFAULT 'todo',
        allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP,
        FOREIGN KEY (source_pending_id) REFERENCES pending_tasks(id)
    );

    CREATE TABLE IF NOT EXISTS notification_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT,
        message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    -- Security Microservice: Unauthorized Access & Anomaly Tracking
    CREATE TABLE IF NOT EXISTS security_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL,
        source_ip TEXT,
        endpoint TEXT,
        severity TEXT DEFAULT 'MEDIUM',
        details TEXT,
        user_agent TEXT,
        blocked INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS security_ip_reputation (
        ip TEXT PRIMARY KEY,
        failed_attempts INTEGER DEFAULT 0,
        is_blocked INTEGER DEFAULT 0,
        first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    -- Usage Analytics & Web Cookie Sessions: Track assistant metrics, tasks, and system performance
    CREATE TABLE IF NOT EXISTS usage_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL,
        category TEXT DEFAULT 'general',
        action_name TEXT,
        status TEXT DEFAULT 'success',
        duration_ms INTEGER DEFAULT 0,
        ram_usage_mb REAL DEFAULT 0.0,
        details_json TEXT,
        session_id TEXT,
        ip_address TEXT,
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    -- User Preferences & Cookie Web State
    CREATE TABLE IF NOT EXISTS user_preferences (
        session_id TEXT PRIMARY KEY,
        theme TEXT DEFAULT 'dark',
        dnd_mode INTEGER DEFAULT 0,
        active_workspace TEXT DEFAULT 'inbox',
        cookie_consent TEXT DEFAULT 'accepted',
        telemetry_enabled INTEGER DEFAULT 1,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        metadata_json TEXT
    );

    -- RAM Guardian Telemetry: Ensure strict <= 28MB RAM compliance
    CREATE TABLE IF NOT EXISTS ram_telemetry (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        process_name TEXT,
        rss_mb REAL,
        vms_mb REAL,
        max_limit_mb REAL DEFAULT 28.0,
        percent_of_max REAL,
        status TEXT DEFAULT 'OK',
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    -- Conversation History: Store all chat conversations and messages
    CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL DEFAULT 'New Conversation',
        preview TEXT DEFAULT '',
        message_count INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS conversation_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('user', 'assistant', 'system')),
        content TEXT NOT NULL,
        metadata_json TEXT DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
    );

    CREATE INDEX IF NOT EXISTS idx_convo_messages_convo_id ON conversation_messages(conversation_id);
    CREATE INDEX IF NOT EXISTS idx_conversations_updated ON conversations(updated_at DESC);
    """)

    cursor.execute("""
        INSERT OR IGNORE INTO system_state (key, value)
        VALUES ('notifications_mode', 'ON')
    """)
    cursor.execute("""
        INSERT OR IGNORE INTO system_state (key, value)
        VALUES ('security_monitoring_active', '1')
    """)
    cursor.execute("""
        INSERT OR IGNORE INTO system_state (key, value)
        VALUES ('security_api_token', 'ast_sec_token_prod_9821')
    """)
    cursor.execute("""
        INSERT OR IGNORE INTO system_state (key, value)
        VALUES ('max_ram_limit_mb', '28.0')
    """)

    conn.commit()
    conn.close()

def record_usage_event(event_type, category="general", action_name=None, status="success",
                       duration_ms=0, ram_usage_mb=0.0, details=None, session_id=None,
                       ip=None, user_agent=None):
    """Log an event into usage_events for assistant telemetry and user analytics."""
    try:
        import json
        conn = get_db_connection()
        cursor = conn.cursor()
        details_str = json.dumps(details) if isinstance(details, (dict, list)) else details
        cursor.execute("""
            INSERT INTO usage_events (event_type, category, action_name, status,
                                      duration_ms, ram_usage_mb, details_json,
                                      session_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (event_type, category, action_name, status, duration_ms, ram_usage_mb,
              details_str, session_id, ip, user_agent))
        conn.commit()
        conn.close()
    except Exception as e:
        pass

def get_current_process_ram_mb():
    """Return the current process RSS memory in MB."""
    try:
        import resource
        # ru_maxrss is in KB on Linux
        kb = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        return round(kb / 1024.0, 2)
    except Exception:
        return 0.0

if __name__ == "__main__":
    init_db()
    print(f"[+] Database schema updated with usage, cookies, and RAM telemetry: {DB_PATH}")
