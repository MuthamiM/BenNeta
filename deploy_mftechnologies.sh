#!/usr/bin/env bash
# BenNeta Deployment Script for mftechnologies.org
# Zero emojis. Strict execution.

set -euo pipefail

echo "[*] Initializing BenNeta Deployment for mftechnologies..."

DOMAIN="benneta.mftechnologies.org"
INSTALL_DIR="/opt/benneta"
SERVICE_NAME="benneta.service"

# 1. Verify sudo privileges
if [ "$EUID" -ne 0 ]; then
  echo "[-] Please run as root or with sudo: sudo ./deploy_mftechnologies.sh"
  exit 1
fi

# 2. Install runtime prerequisites
echo "[*] Updating apt package index and installing dependencies..."
apt-get update -y
apt-get install -y \
  python3 \
  python3-pip \
  php-cli \
  php-fpm \
  php-sqlite3 \
  php-curl \
  curl \
  nginx \
  libnotify-bin

# 3. Create install directory
echo "[*] Creating application directory at $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR"
cp -r config data php python web docs "$INSTALL_DIR/"

# Ensure data directory is writable
chmod -R 775 "$INSTALL_DIR/data"
chmod -R 775 "$INSTALL_DIR/config"
chown -R www-data:www-data "$INSTALL_DIR/data"

# 4. Install systemd service
echo "[*] Configuring systemd service..."
cat << 'EOF' > /etc/systemd/system/benneta.service
[Unit]
Description=BenNeta Intelligence & Security Microservices Platform
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/benneta
ExecStart=/usr/bin/php -S 0.0.0.0:8765 -t /opt/benneta/web
Restart=always
RestartSec=5
Environment=PATH=/usr/local/bin:/usr/bin:/bin

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable benneta.service
systemctl restart benneta.service

# 5. Configure Nginx
if [ -f "benneta-mftechnologies.conf" ]; then
  echo "[*] Installing Nginx configuration..."
  cp benneta-mftechnologies.conf /etc/nginx/sites-available/benneta-mftechnologies.conf
  ln -sf /etc/nginx/sites-available/benneta-mftechnologies.conf /etc/nginx/sites-enabled/
  nginx -t && systemctl reload nginx || echo "[!] Notice: Check Nginx SSL certificate before reloading."
fi

# 6. Verify local service health
echo "[*] Verifying BenNeta API health..."
sleep 2
if curl -s "http://127.0.0.1:8765/api.php?action=status" | grep -q '"success":true'; then
  echo "[+] BenNeta is successfully running and responding on http://127.0.0.1:8765"
  echo "[+] Host domain: $DOMAIN"
else
  echo "[!] Service started. Check logs with: journalctl -u benneta -f"
fi

echo "[+] Deployment to mftechnologies environment complete."
