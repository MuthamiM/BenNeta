#!/usr/bin/env python3
"""
Interactive setup wizard for live IMAP connection on PC (Linux and Windows).
Tests live IMAP connection and stores configuration securely without emojis.
"""

import sys
import getpass
import imaplib
import socket
from config_manager import load_config, save_config

def test_imap_connection(host, port, ssl_mode, username, password):
    print(f"\n[*] Testing connection to {host}:{port} (SSL: {ssl_mode})...")
    try:
        if ssl_mode:
            client = imaplib.IMAP4_SSL(host, port, timeout=12)
        else:
            client = imaplib.IMAP4(host, port, timeout=12)
        
        print(f"[*] Authenticating user: {username}...")
        client.login(username, password)
        status, folders = client.list()
        print("[+] Authentication successful. Connected to live IMAP server.")
        client.logout()
        return True, "Success"
    except socket.timeout:
        return False, "Connection timed out. Verify host and port."
    except imaplib.IMAP4.error as e:
        return False, f"IMAP authentication error: {e}"
    except Exception as e:
        return False, f"Connection failed: {e}"

def run_wizard():
    print("=" * 65)
    print("       ASSISTANT - IMAP CONFIGURATION (PC: LINUX & WINDOWS)")
    print("=" * 65)
    
    cfg = load_config()
    current_imap = cfg.get("imap", {})

    default_host = current_imap.get("host") or ""
    host_prompt = f"IMAP Server Host [{default_host}]: " if default_host else "IMAP Server Host (e.g., mailserver.domain.com, imap.gmail.com): "
    host = input(host_prompt).strip() or default_host
    
    if not host:
        print("[-] Error: IMAP host cannot be empty.")
        sys.exit(1)

    default_port = str(current_imap.get("port") or 993)
    port_input = input(f"IMAP Port [{default_port}]: ").strip() or default_port
    try:
        port = int(port_input)
    except ValueError:
        port = 993

    ssl_input = input("Use SSL/TLS? (y/n) [y]: ").strip().lower()
    use_ssl = ssl_input != 'n'

    default_user = current_imap.get("username") or ""
    user_prompt = f"Email / Username [{default_user}]: " if default_user else "Email / Username: "
    username = input(user_prompt).strip() or default_user

    password = getpass.getpass("Password (hidden): ").strip()
    if not password and current_imap.get("password"):
        use_saved = input("Keep existing saved password? (y/n) [y]: ").strip().lower() != 'n'
        if use_saved:
            password = current_imap.get("password")

    folder = input("Mailbox folder [INBOX]: ").strip() or "INBOX"

    # Test the live connection
    success, message = test_imap_connection(host, port, use_ssl, username, password)
    if not success:
        print(f"\n[!] Connection test failed: {message}")
        retry = input("Save these credentials anyway? (y/n) [n]: ").strip().lower()
        if retry != 'y':
            print("[-] Credentials not saved.")
            return

    cfg["imap"]["host"] = host
    cfg["imap"]["port"] = port
    cfg["imap"]["ssl"] = use_ssl
    cfg["imap"]["username"] = username
    cfg["imap"]["password"] = password
    cfg["imap"]["folder"] = folder

    save_config(cfg)
    print("\n[+] Configuration successfully saved to config/config.json.")

if __name__ == "__main__":
    run_wizard()
