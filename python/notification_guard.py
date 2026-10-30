"""
Notification Guard and Dispatcher Module for PC (Linux and Windows).
Monitors quiet hours, DND mode, and dispatches native desktop notifications with no emojis.
"""

import os
import sys
import subprocess
from datetime import datetime, time
from db import get_db_connection
from config_manager import load_config

IS_WINDOWS = sys.platform.startswith("win")

def check_system_dnd():
    """
    Checks if PC operating system has 'Do Not Disturb' / 'Focus Assist' active.
    Supports Linux (Dunst, GNOME) and Windows.
    """
    if IS_WINDOWS:
        try:
            # Query Windows Focus Assist status via PowerShell registry check
            cmd = [
                "powershell", "-NoProfile", "-Command",
                "(Get-ItemProperty -Path 'HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Notifications\\Settings' -Name 'NOC_GLOBAL_SETTING_TOASTS_ENABLED' -ErrorAction SilentlyContinue).NOC_GLOBAL_SETTING_TOASTS_ENABLED"
            ]
            res = subprocess.run(cmd, capture_output=True, text=True, timeout=2)
            if res.returncode == 0 and res.stdout.strip() == "0":
                return True, "Windows Focus Assist (Do Not Disturb) is active"
        except Exception:
            pass
        return False, None

    # Linux Dunst check
    try:
        res = subprocess.run(["dunstctl", "is-paused"], capture_output=True, text=True, timeout=1)
        if res.returncode == 0 and "true" in res.stdout.lower():
            return True, "Linux Dunst notifications are paused"
    except Exception:
        pass

    # Linux GNOME check
    try:
        res = subprocess.run(
            ["gsettings", "get", "org.gnome.desktop.notifications", "show-banners"],
            capture_output=True, text=True, timeout=1
        )
        if res.returncode == 0 and "false" in res.stdout.lower():
            return True, "GNOME Do Not Disturb is active"
    except Exception:
        pass

    return False, None

def is_within_quiet_hours(check_dt=None):
    cfg = load_config()
    n_cfg = cfg.get("notifications", {})
    if not n_cfg.get("quiet_hours_enabled", False):
        return False, None

    check_dt = check_dt or datetime.now()
    current_time = check_dt.time()

    try:
        start_parts = [int(p) for p in n_cfg.get("quiet_start", "22:00").split(":")]
        end_parts = [int(p) for p in n_cfg.get("quiet_end", "08:00").split(":")]
        start_t = time(start_parts[0], start_parts[1])
        end_t = time(end_parts[0], end_parts[1])

        if start_t > end_t:
            in_quiet = current_time >= start_t or current_time <= end_t
        else:
            in_quiet = start_t <= current_time <= end_t

        if in_quiet:
            return True, f"Quiet Hours ({n_cfg.get('quiet_start')} - {n_cfg.get('quiet_end')})"
    except Exception as e:
        print(f"[-] Error parsing quiet hours: {e}")

    return False, None

def get_current_notification_status(check_dt=None):
    conn = get_db_connection()
    c = conn.cursor()
    c.execute("SELECT value FROM system_state WHERE key = 'notifications_mode'")
    row = c.fetchone()
    db_mode = row["value"] if row else "ON"
    conn.close()

    if db_mode == "OFF":
        return True, "Manual DND (Notifications OFF) is active"

    in_quiet, reason = is_within_quiet_hours(check_dt)
    if in_quiet:
        return True, reason

    if check_dt is None:
        sys_dnd, sys_reason = check_system_dnd()
        if sys_dnd:
            return True, sys_reason

    return False, "Notifications are ON"

def set_notification_mode(mode: str):
    mode = mode.upper()
    if mode not in ("ON", "OFF"):
        raise ValueError("Mode must be 'ON' or 'OFF'")

    conn = get_db_connection()
    c = conn.cursor()
    now_str = datetime.now().isoformat()
    c.execute("UPDATE system_state SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE key = 'notifications_mode'", (mode,))
    if mode == "OFF":
        c.execute("UPDATE system_state SET value = ? WHERE key = 'last_notifications_off_timestamp'", (now_str,))
    c.execute("INSERT INTO notification_logs (event_type, message) VALUES (?, ?)", 
              ("mode_change", f"Notifications switched to {mode}"))
    conn.commit()
    conn.close()
    return mode

def send_desktop_notification(title: str, message: str, urgency: str = "normal"):
    """
    Sends native desktop notification on PC (Linux and Windows) with no emojis.
    """
    cfg = load_config()
    if not cfg.get("notifications", {}).get("desktop_alerts", True):
        return False

    clean_title = f"[ASSISTANT] {title}"
    clean_msg = message

    if IS_WINDOWS:
        try:
            ps_script = f"""
            [Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] | Out-Null
            [Windows.Data.Xml.Dom.XmlDocument, Windows.Data.Xml.Dom.XmlDocument, ContentType = WindowsRuntime] | Out-Null
            $template = @"
            <toast>
                <visual>
                    <binding template="ToastText02">
                        <text id="1">{clean_title}</text>
                        <text id="2">{clean_msg}</text>
                    </binding>
                </visual>
            </toast>
"@
            $xml = New-Object Windows.Data.Xml.Dom.XmlDocument
            $xml.LoadXml($template)
            $toast = [Windows.UI.Notifications.ToastNotification]::new($xml)
            [Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier("Assistant").Show($toast)
            """
            subprocess.run(["powershell", "-NoProfile", "-Command", ps_script], capture_output=True, timeout=4)
            return True
        except Exception:
            return False
    else:
        # Linux notify-send
        try:
            urgency_flag = "critical" if urgency.lower() == "high" else "normal"
            subprocess.run(["notify-send", "-u", urgency_flag, clean_title, clean_msg], capture_output=True, timeout=2)
            return True
        except Exception:
            return False

if __name__ == "__main__":
    is_off, reason = get_current_notification_status()
    print(f"Current Notification Status: {'OFF (' + reason + ')' if is_off else 'ON'}")
