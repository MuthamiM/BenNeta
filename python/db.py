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

    conn.commit()
    conn.close()

if __name__ == "__main__":
    init_db()
    print(f"[+] Database initialized with security tables at: {DB_PATH}")
