#!/usr/bin/env bash
# BenNeta Debian (.deb) Package Builder
# Produces a production-ready .deb for amd64 Linux.
# Zero emojis. Strict execution.

set -euo pipefail

PACKAGE_NAME="benneta"
VERSION="1.0.0"
ARCH="amd64"
DIST_DIR="$(pwd)/dist"
PKG_ROOT="$DIST_DIR/${PACKAGE_NAME}_${VERSION}_${ARCH}"
DEB_OUT="$DIST_DIR/${PACKAGE_NAME}_${VERSION}_${ARCH}.deb"

echo "[*] Building Debian package: ${PACKAGE_NAME}_${VERSION}_${ARCH}.deb"

# ------------------------------------------------------------------
# 0. Clean prior builds
# ------------------------------------------------------------------
rm -rf "$PKG_ROOT" "$DEB_OUT"
mkdir -p "$PKG_ROOT/DEBIAN"
mkdir -p "$PKG_ROOT/opt/benneta/bin"
mkdir -p "$PKG_ROOT/opt/benneta/data"
mkdir -p "$PKG_ROOT/opt/benneta/config"
mkdir -p "$PKG_ROOT/usr/local/bin"
mkdir -p "$PKG_ROOT/etc/systemd/system"
mkdir -p "$PKG_ROOT/usr/share/applications"
mkdir -p "$PKG_ROOT/usr/share/icons/hicolor/scalable/apps"

# ------------------------------------------------------------------
# 1. DEBIAN/control
# ------------------------------------------------------------------
cat > "$PKG_ROOT/DEBIAN/control" << 'CTRL'
Package: benneta
Version: 1.0.0
Section: utils
Priority: optional
Architecture: amd64
Depends: python3 (>= 3.8), php-cli (>= 8.0), php-sqlite3, php-curl, curl, libnotify-bin
Maintainer: BenNeta Team <info@mftechnologies.org>
Recommends: nginx
Homepage: https://mftechnologies.org
Description: BenNeta - PC Intelligence and Security Microservices
 Personal assistant and automated microservices platform for Linux.
 Features live Gmail inbox triage via IMAP SSL, multi-factor spam and
 phishing classification, Brave Search web research for smart email
 reply drafting, API security sentinel with exploit signature
 detection and Z-score rate-limit anomaly engine, and 10 live
 automated system and infrastructure tasks. Native systemd service,
 desktop launcher, and CLI included.
CTRL

# conffiles: none shipped -- postinst creates config.json from example on first install

# ------------------------------------------------------------------
# 3. DEBIAN/postinst
# ------------------------------------------------------------------
cat > "$PKG_ROOT/DEBIAN/postinst" << 'POST'
#!/bin/sh
set -e

# Ensure writable data & config dirs
mkdir -p /opt/benneta/data /opt/benneta/config
chmod 775 /opt/benneta/data /opt/benneta/config

# Initialize SQLite database on first install
if [ ! -f /opt/benneta/data/assistant.db ]; then
  python3 /opt/benneta/python/db.py 2>/dev/null || true
fi

# Copy example config if user has none
if [ ! -f /opt/benneta/config/config.json ]; then
  cp /opt/benneta/config/config.example.json /opt/benneta/config/config.json
  chmod 600 /opt/benneta/config/config.json
fi

chmod +x /usr/local/bin/benneta 2>/dev/null || true
chmod +x /opt/benneta/bin/assistant-daemon 2>/dev/null || true

# Systemd integration
if command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload           || true
  systemctl enable benneta.service  || true
  systemctl restart benneta.service || true
fi

# Desktop integration
if command -v update-desktop-database >/dev/null 2>&1; then
  update-desktop-database -q /usr/share/applications || true
fi
if command -v gtk-update-icon-cache >/dev/null 2>&1; then
  gtk-update-icon-cache -q /usr/share/icons/hicolor || true
fi

echo ""
echo "============================================"
echo "  BenNeta 1.0.0 installed successfully"
echo "============================================"
echo ""
echo "  Dashboard:  http://localhost:8765"
echo "  CLI:        benneta help"
echo ""
echo "  First-time setup:"
echo "    1. Edit /opt/benneta/config/config.json"
echo "    2. Add your Gmail App Password"
echo "    3. Add your Brave Search API key"
echo "    4. sudo systemctl restart benneta"
echo ""
exit 0
POST
chmod 0755 "$PKG_ROOT/DEBIAN/postinst"

# ------------------------------------------------------------------
# 4. DEBIAN/prerm
# ------------------------------------------------------------------
cat > "$PKG_ROOT/DEBIAN/prerm" << 'PRERM'
#!/bin/sh
set -e
if command -v systemctl >/dev/null 2>&1; then
  systemctl stop benneta.service    || true
  systemctl disable benneta.service || true
fi
exit 0
PRERM
chmod 0755 "$PKG_ROOT/DEBIAN/prerm"

# ------------------------------------------------------------------
# 5. DEBIAN/postrm
# ------------------------------------------------------------------
cat > "$PKG_ROOT/DEBIAN/postrm" << 'POSTRM'
#!/bin/sh
set -e
if command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload || true
fi
if command -v update-desktop-database >/dev/null 2>&1; then
  update-desktop-database -q /usr/share/applications || true
fi
exit 0
POSTRM
chmod 0755 "$PKG_ROOT/DEBIAN/postrm"

# ------------------------------------------------------------------
# 6. Populate /opt/benneta  (application code)
# ------------------------------------------------------------------
echo "[*] Copying application code..."

# Ship only the example config (no credentials)
cp config/config.example.json "$PKG_ROOT/opt/benneta/config/config.example.json"

# PHP backend
cp -r php "$PKG_ROOT/opt/benneta/"

# Python microservices
cp -r python "$PKG_ROOT/opt/benneta/"

# Web frontend
cp -r web "$PKG_ROOT/opt/benneta/"

# Documentation
cp -r docs "$PKG_ROOT/opt/benneta/"

# Nginx template & deploy helper
cp benneta-mftechnologies.conf "$PKG_ROOT/opt/benneta/"
cp deploy_mftechnologies.sh    "$PKG_ROOT/opt/benneta/"

# Compiled Rust daemon
if [ -f "rust/target/release/assistant-daemon" ]; then
  cp rust/target/release/assistant-daemon "$PKG_ROOT/opt/benneta/bin/"
fi

# House-cleaning
find "$PKG_ROOT" -type d -name "__pycache__" -exec rm -rf {} + 2>/dev/null || true
find "$PKG_ROOT" -name "*.pyc" -delete 2>/dev/null || true
find "$PKG_ROOT" -name ".DS_Store" -delete 2>/dev/null || true

# ------------------------------------------------------------------
# 7. /usr/local/bin/benneta  (CLI launcher)
# ------------------------------------------------------------------
cat > "$PKG_ROOT/usr/local/bin/benneta" << 'CLI'
#!/usr/bin/env bash
# BenNeta CLI  --  Intelligence & Automation
set -e
APP="/opt/benneta"
API="http://127.0.0.1:8765/api.php"
CMD="${1:-help}"
shift 2>/dev/null || true

case "$CMD" in
  status)
    echo "[benneta] Service status:"
    systemctl is-active benneta.service 2>/dev/null || echo "  service not running"
    echo "[benneta] API health:"
    curl -sf "$API?action=status" | python3 -m json.tool 2>/dev/null \
      || echo "  API not responding on port 8765"
    ;;
  start)   sudo systemctl start   benneta.service && echo "[+] Started."  ;;
  stop)    sudo systemctl stop    benneta.service && echo "[+] Stopped."  ;;
  restart) sudo systemctl restart benneta.service && echo "[+] Restarted.";;
  scan)
    echo "[benneta] Scanning today's inbox..."
    curl -sf "$API?action=get_gmail_today" | python3 -m json.tool
    ;;
  security)
    echo "[benneta] Security telemetry:"
    curl -sf "$API?action=get_security_metrics" | python3 -m json.tool
    ;;
  tasks)
    echo "[benneta] Automated tasks:"
    curl -sf "$API?action=get_automated_tasks" | python3 -m json.tool
    ;;
  research)
    Q="${*:-linux security best practices}"
    echo "[benneta] Brave Search: $Q"
    python3 "$APP/python/research_replier.py" search "$Q"
    ;;
  draft)
    SUBJ="${1:-Follow up}"
    FROM="${2:-contact@example.com}"
    BODY="${3:-}"
    echo "[benneta] Drafting reply..."
    python3 "$APP/python/research_replier.py" draft "$SUBJ" "$FROM" "$BODY"
    ;;
  web)
    echo "[benneta] Opening dashboard..."
    xdg-open "http://localhost:8765" 2>/dev/null &
    ;;
  logs) journalctl -u benneta.service -f -n 50 ;;
  config)
    echo "[benneta] Config file: /opt/benneta/config/config.json"
    if [ -f "$APP/config/config.json" ]; then
      cat "$APP/config/config.json"
    else
      echo "  No config.json found. Copy the example:"
      echo "    sudo cp $APP/config/config.example.json $APP/config/config.json"
    fi
    ;;
  version) echo "BenNeta 1.0.0" ;;
  help|*)
    cat << 'HELP'
BenNeta CLI  --  Intelligence & Automation

Usage: benneta <command> [args]

  status        Service and API health check
  start         Start the background service
  stop          Stop the background service
  restart       Restart the background service
  scan          Fetch today's Gmail inbox with spam detection
  security      View API security events and blocked IPs
  tasks         List and run automated infrastructure tasks
  research <q>  Live Brave Search web research
  draft <s> <f> Generate a research-backed email reply draft
  web           Open the dashboard in your browser
  logs          Tail the systemd service journal
  config        Show current configuration
  version       Print version

Dashboard:  http://localhost:8765
Config:     /opt/benneta/config/config.json
HELP
    ;;
esac
CLI
chmod 0755 "$PKG_ROOT/usr/local/bin/benneta"

# ------------------------------------------------------------------
# 8. /etc/systemd/system/benneta.service
# ------------------------------------------------------------------
cat > "$PKG_ROOT/etc/systemd/system/benneta.service" << 'SVC'
[Unit]
Description=BenNeta Intelligence & Security Microservices
After=network.target
Documentation=https://mftechnologies.org

[Service]
Type=simple
WorkingDirectory=/opt/benneta
ExecStart=/usr/bin/php -d memory_limit=24M -S 0.0.0.0:8765 -t /opt/benneta/web
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
chmod 0644 "$PKG_ROOT/etc/systemd/system/benneta.service"

# ------------------------------------------------------------------
# 9. Desktop entry
# ------------------------------------------------------------------
cat > "$PKG_ROOT/usr/share/applications/benneta.desktop" << 'DESK'
[Desktop Entry]
Version=1.0
Type=Application
Name=BenNeta
GenericName=AI Assistant
Comment=Intelligence, Gmail Triage, and Security Microservices
Exec=benneta web
Icon=benneta
Terminal=false
Categories=Utility;Network;Office;
Keywords=assistant;gmail;security;benneta;
StartupNotify=true
DESK
chmod 0644 "$PKG_ROOT/usr/share/applications/benneta.desktop"

# ------------------------------------------------------------------
# 10. SVG icon  (terracotta starburst  #d97757)
# ------------------------------------------------------------------
cat > "$PKG_ROOT/usr/share/icons/hicolor/scalable/apps/benneta.svg" << 'ICON'
<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
  <rect width="128" height="128" rx="28" fill="#d97757"/>
  <g transform="translate(64,64)">
    <line x1="0" y1="-38" x2="0" y2="38" stroke="#fff" stroke-width="11" stroke-linecap="round"/>
    <line x1="-38" y1="0" x2="38" y2="0" stroke="#fff" stroke-width="11" stroke-linecap="round"/>
    <line x1="-27" y1="-27" x2="27" y2="27" stroke="#fff" stroke-width="11" stroke-linecap="round"/>
    <line x1="-27" y1="27" x2="27" y2="-27" stroke="#fff" stroke-width="11" stroke-linecap="round"/>
  </g>
</svg>
ICON
chmod 0644 "$PKG_ROOT/usr/share/icons/hicolor/scalable/apps/benneta.svg"

# ------------------------------------------------------------------
# 11. Verify structure then build
# ------------------------------------------------------------------
echo "[*] Package tree:"
find "$PKG_ROOT" -not -path '*/\.*' | head -60

echo ""
echo "[*] Assembling .deb with dpkg-deb..."
dpkg-deb --build --root-owner-group "$PKG_ROOT" "$DEB_OUT"

echo ""
echo "[+] Package built successfully:"
ls -lh "$DEB_OUT"

echo ""
echo "[*] Package info:"
dpkg-deb -I "$DEB_OUT"

echo ""
echo "To install on your laptop:"
echo "  sudo dpkg -i $DEB_OUT"
echo "  sudo apt-get install -f   # resolve any missing deps"
echo ""
echo "After install:"
echo "  sudo nano /opt/benneta/config/config.json   # add credentials"
echo "  benneta restart"
echo "  benneta web"
