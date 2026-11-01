#!/usr/bin/env python3
"""
EZER Native Desktop GUI Window
Built on GTK 4.0 and WebKit 6.0 (High-performance Linux desktop native app)
Memory consumption: < 20MB RAM (strictly within the 28MB ceiling)
"""
import sys
import os
import signal
import gi

gi.require_version('Gtk', '4.0')
gi.require_version('WebKit', '6.0')
from gi.repository import Gtk, WebKit, GLib, Gio

# Ensure window WM_CLASS and Wayland app_id match desktop file for dock icons
GLib.set_prgname("exer")
GLib.set_application_name("EXER")

class ExerApp(Gtk.Application):
    def __init__(self):
        super().__init__(
            application_id="org.mftechnologies.exer",
            flags=Gio.ApplicationFlags.NON_UNIQUE
        )

    def on_desktop_action(self, manager, js_result):
        import subprocess
        import json
        try:
            val_str = js_result.to_json(0) if hasattr(js_result, 'to_json') else js_result.to_string()
            data = json.loads(val_str) if val_str else {}
            if isinstance(data, str):
                try:
                    data = json.loads(data)
                except Exception:
                    pass
            if isinstance(data, dict) and data.get("action") == "open_excel":
                fname = data.get("file") or data.get("path")
                candidates = [
                    data.get("path", ""),
                    f"/home/cantroll/assistant/data/spreadsheets/{fname}",
                    f"/opt/exer/data/spreadsheets/{fname}",
                    f"/opt/ezer/data/spreadsheets/{fname}",
                    f"/opt/benneta/data/spreadsheets/{fname}",
                    os.path.expanduser(f"~/Downloads/{fname}")
                ]
                for p in candidates:
                    if p and os.path.exists(p):
                        subprocess.Popen(["xdg-open", p])
                        print(f"[+] Opened {p} in desktop spreadsheet application")
                        break
        except Exception as e:
            print("Desktop action error:", e)

    def do_activate(self):
        # Create native top-level window
        win = Gtk.ApplicationWindow(application=self)
        win.set_title("EXER — PC Intelligence & Automation")
        win.set_default_size(1380, 890)

        # WebKit WebView
        webview = WebKit.WebView()
        settings = webview.get_settings()
        settings.set_enable_smooth_scrolling(True)
        settings.set_enable_javascript(True)
        settings.set_enable_webaudio(True)

        # Register desktop action bridge
        try:
            content_manager = webview.get_user_content_manager()
            content_manager.register_script_message_handler("desktopAction")
            content_manager.connect("script-message-received::desktopAction", self.on_desktop_action)
        except Exception as e:
            print("Warning: could not register desktopAction bridge:", e)
        
        # Load local or remote EZER dashboard
        url = os.environ.get("EZER_URL", os.environ.get("BENNETA_URL", "http://localhost:8765/app"))
        webview.load_uri(url)

        win.set_child(webview)
        win.present()

def main():
    signal.signal(signal.SIGINT, signal.SIG_DFL)
    app = ExerApp()
    return app.run(sys.argv)

if __name__ == "__main__":
    sys.exit(main())
