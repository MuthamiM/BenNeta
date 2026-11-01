#!/usr/bin/env bash
# EZER Debian (.deb) Package Builder
# Produces a production-ready .deb for amd64 Linux.
set -euo pipefail

PACKAGE_NAME="exer"
VERSION="2.0.0"
ARCH="amd64"
REPO_ROOT="/home/cantroll/assistant"
DIST_DIR="$REPO_ROOT/dist"
PKG_ROOT="$DIST_DIR/${PACKAGE_NAME}_${VERSION}_${ARCH}"
DEB_OUT="$DIST_DIR/${PACKAGE_NAME}_${VERSION}_${ARCH}.deb"

echo "[*] Building Debian package: ${PACKAGE_NAME}_${VERSION}_${ARCH}.deb"

# ------------------------------------------------------------------
# 0. Clean prior builds
# ------------------------------------------------------------------
rm -rf "$PKG_ROOT" "$DEB_OUT"
mkdir -p "$PKG_ROOT/DEBIAN"
mkdir -p "$PKG_ROOT/opt/exer/bin"
mkdir -p "$PKG_ROOT/opt/exer/data"
mkdir -p "$PKG_ROOT/opt/exer/config"
mkdir -p "$PKG_ROOT/usr/local/bin"
mkdir -p "$PKG_ROOT/etc/systemd/system"
mkdir -p "$PKG_ROOT/usr/share/applications"
mkdir -p "$PKG_ROOT/usr/share/icons/hicolor/scalable/apps"
mkdir -p "$PKG_ROOT/usr/share/icons/hicolor/16x16/apps"
mkdir -p "$PKG_ROOT/usr/share/icons/hicolor/32x32/apps"
mkdir -p "$PKG_ROOT/usr/share/icons/hicolor/192x192/apps"
mkdir -p "$PKG_ROOT/usr/share/icons/hicolor/512x512/apps"

# ------------------------------------------------------------------
# 1. DEBIAN/control
# ------------------------------------------------------------------
cat > "$PKG_ROOT/DEBIAN/control" << 'CTRL'
Package: exer
Version: 2.0.0
Section: utils
Priority: optional
Architecture: amd64
Replaces: benneta, ezer
Conflicts: benneta, ezer
Depends: python3 (>= 3.8), python3-gi, gir1.2-gtk-4.0, gir1.2-webkit-6.0 | gir1.2-webkit2-4.1, php-cli (>= 8.0), php-sqlite3, php-curl, curl, libnotify-bin
Maintainer: EXER Team <info@mftechnologies.org>
Homepage: https://mftechnologies.org
Description: EXER - PC Intelligence and Automation Microservices
 Personal assistant and automated microservices platform for Linux.
 Features live Gmail inbox triage via IMAP SSL, multi-factor spam and
 phishing classification, Brave Search web research for smart email
 reply drafting, API security sentinel with exploit signature
 detection and Z-score rate-limit anomaly engine, and live
 automated system and infrastructure tasks. Native GTK4 desktop
 application, systemd service, and CLI included.
CTRL

# ------------------------------------------------------------------
# 2. DEBIAN/postinst
# ------------------------------------------------------------------
cat > "$PKG_ROOT/DEBIAN/postinst" << 'POST'
#!/bin/sh
set -e

# Symlink /opt/benneta -> /opt/exer for complete backward compatibility
if [ ! -d /opt/benneta ] && [ ! -L /opt/benneta ]; then
  ln -s /opt/exer /opt/benneta 2>/dev/null || true
fi

# Ensure writable data & config dirs
mkdir -p /opt/exer/data /opt/exer/config
chmod 775 /opt/exer/data /opt/exer/config 2>/dev/null || true

# Initialize SQLite database on first install if missing
if [ ! -f /opt/exer/data/assistant.db ]; then
  python3 /opt/exer/python/db.py 2>/dev/null || true
fi

# Copy example config if user has none
if [ ! -f /opt/exer/config/config.json ] && [ -f /opt/exer/config/config.example.json ]; then
  cp /opt/exer/config/config.example.json /opt/exer/config/config.json
  chmod 600 /opt/exer/config/config.json 2>/dev/null || true
fi

# Binary permissions & symlinks
chmod +x /usr/local/bin/exer 2>/dev/null || true
chmod +x /opt/exer/bin/* 2>/dev/null || true

# Ensure legacy benneta CLI maps to ezer
if [ ! -f /usr/local/bin/benneta ] && [ ! -L /usr/local/bin/benneta ]; then
  ln -s /usr/local/bin/exer /usr/local/bin/benneta 2>/dev/null || true
fi

# Clean up any leftover benneta desktop entries
rm -f /usr/share/applications/benneta.desktop 2>/dev/null || true
rm -f /usr/share/applications/org.mftechnologies.benneta.desktop 2>/dev/null || true

# Systemd integration
if command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload           || true
  systemctl enable exer.service     || true
fi

# Desktop & Icon database refresh
if command -v update-desktop-database >/dev/null 2>&1; then
  update-desktop-database -q /usr/share/applications || true
fi
if command -v gtk-update-icon-cache >/dev/null 2>&1; then
  gtk-update-icon-cache -q /usr/share/icons/hicolor || true
fi

echo ""
echo "============================================"
echo "  EXER 2.0.0 installed successfully"
echo "============================================"
echo ""
echo "  Desktop App: Click 'EXER' in applications or run 'exer app'"
echo "  Web UI:      https://benneta.mftechnologies.org/app (or http://localhost:8765)"
echo "  CLI:         exer help"
echo ""
exit 0
POST
chmod 0755 "$PKG_ROOT/DEBIAN/postinst"

# ------------------------------------------------------------------
# 3. DEBIAN/prerm
# ------------------------------------------------------------------
cat > "$PKG_ROOT/DEBIAN/prerm" << 'PRERM'
#!/bin/sh
set -e
if command -v systemctl >/dev/null 2>&1; then
  systemctl stop exer.service    2>/dev/null || true
  systemctl disable exer.service 2>/dev/null || true
  systemctl stop benneta.service    2>/dev/null || true
  systemctl disable benneta.service 2>/dev/null || true
fi
exit 0
PRERM
chmod 0755 "$PKG_ROOT/DEBIAN/prerm"

# ------------------------------------------------------------------
# 4. DEBIAN/postrm
# ------------------------------------------------------------------
cat > "$PKG_ROOT/DEBIAN/postrm" << 'POSTRM'
#!/bin/sh
set -e
if command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload 2>/dev/null || true
fi
if command -v update-desktop-database >/dev/null 2>&1; then
  update-desktop-database -q /usr/share/applications 2>/dev/null || true
fi
if command -v gtk-update-icon-cache >/dev/null 2>&1; then
  gtk-update-icon-cache -q /usr/share/icons/hicolor 2>/dev/null || true
fi
exit 0
POSTRM
chmod 0755 "$PKG_ROOT/DEBIAN/postrm"

# ------------------------------------------------------------------
# 5. Populate /opt/exer (application code)
# ------------------------------------------------------------------
echo "[*] Copying application code..."
cp config/config.example.json "$PKG_ROOT/opt/exer/config/config.example.json"
cp -r php "$PKG_ROOT/opt/exer/"
cp -r python "$PKG_ROOT/opt/exer/"
cp -r web "$PKG_ROOT/opt/exer/"
cp -r docs "$PKG_ROOT/opt/exer/"
cp benneta-mftechnologies.conf "$PKG_ROOT/opt/exer/"
cp deploy_mftechnologies.sh    "$PKG_ROOT/opt/exer/"

# Binaries
if [ -f "$REPO_ROOT/rust/target/release/assistant-daemon" ]; then
  cp "$REPO_ROOT/rust/target/release/assistant-daemon" "$PKG_ROOT/opt/exer/bin/"
fi
if [ -f "$REPO_ROOT/rust/target/release/ezer" ]; then
  cp "$REPO_ROOT/rust/target/release/ezer" "$PKG_ROOT/opt/exer/bin/"
fi

# House-cleaning
find "$PKG_ROOT" -type d -name "__pycache__" -exec rm -rf {} + 2>/dev/null || true
find "$PKG_ROOT" -name "*.pyc" -delete 2>/dev/null || true
find "$PKG_ROOT" -name ".DS_Store" -delete 2>/dev/null || true

# ------------------------------------------------------------------
# 6. /usr/local/bin/exer (CLI & Desktop launcher)
# ------------------------------------------------------------------
cat > "$PKG_ROOT/usr/local/bin/exer" << 'CLI'
#!/usr/bin/env bash
# EZER CLI & Desktop Launcher -- Intelligence & Automation
set -e
APP="/opt/exer"
[ -d "$APP" ] || APP="/opt/benneta"
API="http://127.0.0.1:8765/api.php"
CMD="${1:-app}"
shift 2>/dev/null || true

case "$CMD" in
  app)
    # Check if native desktop app is available and DISPLAY/WAYLAND is active
    if [ -n "$DISPLAY" ] || [ -n "$WAYLAND_DISPLAY" ]; then
      if [ -f "$APP/python/native_app.py" ]; then
        exec python3 "$APP/python/native_app.py" "$@"
      elif [ -f "$APP/bin/ezer" ]; then
        exec "$APP/bin/ezer" app "$@"
      fi
    fi
    # Fallback to browser
    xdg-open "https://benneta.mftechnologies.org/app" 2>/dev/null || xdg-open "http://localhost:8765/app" 2>/dev/null &
    ;;
  web)
    echo "[ezer] Opening EZER dashboard in browser..."
    xdg-open "https://benneta.mftechnologies.org/app" 2>/dev/null || xdg-open "http://localhost:8765/app" 2>/dev/null &
    ;;
  status)
    echo "[ezer] Service status:"
    systemctl is-active exer.service 2>/dev/null || systemctl is-active benneta.service 2>/dev/null || echo "  service not running"
    echo "[ezer] API health:"
    curl -sf "$API?action=status" | python3 -m json.tool 2>/dev/null \
      || curl -sf "https://benneta.mftechnologies.org/api.php?action=status" | python3 -m json.tool 2>/dev/null \
      || echo "  API not responding"
    ;;
  start)   sudo systemctl start   exer.service 2>/dev/null || sudo systemctl start benneta.service && echo "[+] Started."  ;;
  stop)    sudo systemctl stop    exer.service 2>/dev/null || sudo systemctl stop benneta.service && echo "[+] Stopped."  ;;
  restart) sudo systemctl restart exer.service 2>/dev/null || sudo systemctl restart benneta.service && echo "[+] Restarted.";;
  scan)
    echo "[ezer] Scanning today's inbox..."
    curl -sf "$API?action=get_gmail_today" | python3 -m json.tool \
      || curl -sf "https://benneta.mftechnologies.org/api.php?action=get_gmail_today" | python3 -m json.tool
    ;;
  security)
    echo "[ezer] Security telemetry:"
    curl -sf "$API?action=get_security_metrics" | python3 -m json.tool \
      || curl -sf "https://benneta.mftechnologies.org/api.php?action=get_security_metrics" | python3 -m json.tool
    ;;
  tasks)
    echo "[ezer] Automated tasks:"
    curl -sf "$API?action=get_automated_tasks" | python3 -m json.tool \
      || curl -sf "https://benneta.mftechnologies.org/api.php?action=get_automated_tasks" | python3 -m json.tool
    ;;
  research)
    Q="${*:-linux security best practices}"
    echo "[ezer] Brave Search: $Q"
    python3 "$APP/python/research_replier.py" search "$Q"
    ;;
  draft)
    SUBJ="${1:-Follow up}"
    FROM="${2:-contact@example.com}"
    BODY="${3:-}"
    echo "[ezer] Drafting reply..."
    python3 "$APP/python/research_replier.py" draft "$SUBJ" "$FROM" "$BODY"
    ;;
  logs) journalctl -u exer.service -f -n 50 2>/dev/null || journalctl -u benneta.service -f -n 50 ;;
  config)
    echo "[ezer] Config file: $APP/config/config.json"
    if [ -f "$APP/config/config.json" ]; then
      cat "$APP/config/config.json"
    else
      echo "  No config.json found. Copy the example:"
      echo "    sudo cp $APP/config/config.example.json $APP/config/config.json"
    fi
    ;;
  version) echo "EZER 1.0.0" ;;
  help|*)
    cat << 'HELP'
EZER CLI & Desktop Launcher -- Intelligence & Automation

Usage: ezer <command> [args]

  app           Launch native GTK4 desktop application window
  web           Open web dashboard in browser
  status        Service and API health check
  start         Start the background service
  stop          Stop the background service
  restart       Restart the background service
  scan          Fetch today's Gmail inbox with spam detection
  security      View API security events and blocked IPs
  tasks         List and run automated infrastructure tasks
  research <q>  Live Brave Search web research
  draft <s> <f> Generate a research-backed email reply draft
  logs          Tail the service journal
  config        Show current configuration
  version       Print version

HELP
    ;;
esac
CLI
chmod 0755 "$PKG_ROOT/usr/local/bin/exer"

# ------------------------------------------------------------------
# 7. /etc/systemd/system/exer.service
# ------------------------------------------------------------------
cat > "$PKG_ROOT/etc/systemd/system/exer.service" << 'SVC'
[Unit]
Description=EXER Intelligence & Security Microservices
After=network.target
Documentation=https://mftechnologies.org

[Service]
Type=simple
WorkingDirectory=/opt/exer
ExecStart=/usr/bin/php -d memory_limit=24M -S 0.0.0.0:8765 -t /opt/exer/web
Restart=on-failure
RestartSec=3
StandardOutput=journal
StandardError=journal
MemoryHigh=24M
MemoryMax=28M
Environment=PATH=/usr/local/bin:/usr/bin:/bin

[Install]
WantedBy=multi-user.target
SVC
chmod 0644 "$PKG_ROOT/etc/systemd/system/exer.service"

# ------------------------------------------------------------------
# 8. Desktop entries
# ------------------------------------------------------------------
cat > "$PKG_ROOT/usr/share/applications/exer.desktop" << 'DESK'
[Desktop Entry]
Version=1.0
Type=Application
Name=EXER
GenericName=AI Assistant & Security Sentinel
Comment=EXER PC Intelligence & Automation
Exec=/usr/local/bin/exer app
Icon=exer
Terminal=false
Categories=Utility;Network;Office;Development;
Keywords=assistant;gmail;security;tasks;exer;
StartupNotify=true
StartupWMClass=exer
DESK
chmod 0644 "$PKG_ROOT/usr/share/applications/exer.desktop"

# ------------------------------------------------------------------
# 9. Icons
# ------------------------------------------------------------------
cp "$REPO_ROOT/web/assets/icon.svg" "$PKG_ROOT/usr/share/icons/hicolor/scalable/apps/exer.svg"
chmod 0644 "$PKG_ROOT/usr/share/icons/hicolor/scalable/apps/exer.svg"

if [ -f "$REPO_ROOT/web/assets/favicon-16x16.png" ]; then
  cp "$REPO_ROOT/web/assets/favicon-16x16.png" "$PKG_ROOT/usr/share/icons/hicolor/16x16/apps/exer.png"
fi
if [ -f "$REPO_ROOT/web/assets/favicon-32x32.png" ]; then
  cp "$REPO_ROOT/web/assets/favicon-32x32.png" "$PKG_ROOT/usr/share/icons/hicolor/32x32/apps/exer.png"
fi
if [ -f "$REPO_ROOT/web/assets/icon-192.png" ]; then
  cp "$REPO_ROOT/web/assets/icon-192.png" "$PKG_ROOT/usr/share/icons/hicolor/192x192/apps/exer.png"
fi
if [ -f "$REPO_ROOT/web/assets/icon-512.png" ]; then
  cp "$REPO_ROOT/web/assets/icon-512.png" "$PKG_ROOT/usr/share/icons/hicolor/512x512/apps/exer.png"
fi

# ------------------------------------------------------------------
# 10. Build .deb package
# ------------------------------------------------------------------
echo "[*] Assembling .deb with dpkg-deb..."
dpkg-deb --build --root-owner-group "$PKG_ROOT" "$DEB_OUT"

echo ""
echo "[+] Package built successfully: $DEB_OUT"
dpkg-deb -I "$DEB_OUT"
