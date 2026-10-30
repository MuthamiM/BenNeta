"""
Configuration Manager for the Assistant.
Handles reading and validating IMAP credentials, notification rules, and settings for PC (Linux and Windows).
"""

import os
import json

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONFIG_PATH = os.path.join(PROJECT_ROOT, "config", "config.json")

def get_default_todo_path():
    home = os.path.expanduser("~")
    return os.path.join(home, "todo.txt")

DEFAULT_CONFIG = {
    "imap": {
        "host": "",
        "port": 993,
        "ssl": True,
        "username": "",
        "password": "",
        "folder": "INBOX",
        "search_criterion": "UNSEEN",
        "fetch_limit": 25
    },
    "notifications": {
        "desktop_alerts": True,
        "mode": "AUTO",
        "quiet_hours_enabled": True,
        "quiet_start": "22:00",
        "quiet_end": "08:00"
    },
    "rules": [
        {
            "id": 1,
            "name": "Critical Infrastructure Outage",
            "match_subject": ["outage", "server down", "critical alert", "failed"],
            "match_sender": ["alert@", "monitoring@"],
            "action": "auto_approve_and_notify",
            "target_priority": "HIGH",
            "command": ""
        },
        {
            "id": 2,
            "name": "Meetings and Calendar Invites",
            "match_subject": ["invitation:", "meeting", "zoom link", "schedule call"],
            "match_sender": [],
            "action": "auto_approve",
            "target_priority": "MEDIUM",
            "command": ""
        }
    ],
    "daemon": {
        "poll_interval_seconds": 60,
        "sync_todo_txt": True,
        "todo_txt_path": get_default_todo_path()
    }
}

def load_config():
    if not os.path.exists(CONFIG_PATH):
        save_config(DEFAULT_CONFIG)
        return DEFAULT_CONFIG
    
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            cfg = json.load(f)
            for section, values in DEFAULT_CONFIG.items():
                if section not in cfg:
                    cfg[section] = values
                elif isinstance(values, dict):
                    for k, v in values.items():
                        if k not in cfg[section]:
                            cfg[section][k] = v
            if "rules" not in cfg:
                cfg["rules"] = DEFAULT_CONFIG["rules"]
            return cfg
    except Exception as e:
        print(f"[-] Warning: Failed to parse {CONFIG_PATH}: {e}. Using defaults.")
        return DEFAULT_CONFIG

def save_config(config_data):
    os.makedirs(os.path.dirname(CONFIG_PATH), exist_ok=True)
    with open(CONFIG_PATH, "w", encoding="utf-8") as f:
        json.dump(config_data, f, indent=4)
    try:
        os.chmod(CONFIG_PATH, 0o600)
    except Exception:
        pass

def is_configured():
    cfg = load_config()
    imap = cfg.get("imap", {})
    return bool(imap.get("host") and imap.get("username") and imap.get("password"))
