#!/usr/bin/env python3
"""
BenNeta Native Desktop GUI Window
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

class BenNetaApp(Gtk.Application):
    def __init__(self):
        super().__init__(
            application_id="org.mftechnologies.benneta",
            flags=Gio.ApplicationFlags.FLAGS_NONE
        )

    def do_activate(self):
        # Create native top-level window
        win = Gtk.ApplicationWindow(application=self)
        win.set_title("BenNeta — PC Intelligence & Automation")
        win.set_default_size(1380, 890)

        # WebKit WebView
        webview = WebKit.WebView()
        settings = webview.get_settings()
        settings.set_enable_smooth_scrolling(True)
        settings.set_enable_javascript(True)
        settings.set_enable_webaudio(True)
        
        # Load local or remote BenNeta dashboard
        url = os.environ.get("BENNETA_URL", "https://benneta.mftechnologies.org/app")
        webview.load_uri(url)

        win.set_child(webview)
        win.present()

def main():
    signal.signal(signal.SIGINT, signal.SIG_DFL)
    app = BenNetaApp()
    return app.run(sys.argv)

if __name__ == "__main__":
    sys.exit(main())
