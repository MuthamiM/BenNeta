"""
Database schema and connection manager for the Assistant.
Cross-platform support for Linux and Windows.
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
    """)

    cursor.execute("""
        INSERT OR IGNORE INTO system_state (key, value)
        VALUES ('notifications_mode', 'ON')
    """)
    cursor.execute("""
        INSERT OR IGNORE INTO system_state (key, value)
        VALUES ('last_notifications_off_timestamp', '')
    """)
    cursor.execute("""
        INSERT OR IGNORE INTO system_state (key, value)
        VALUES ('last_sync_timestamp', '')
    """)

    conn.commit()
    conn.close()

if __name__ == "__main__":
    init_db()
    print(f"[+] Database initialized at: {DB_PATH}")
