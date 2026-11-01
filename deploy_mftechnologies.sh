#!/usr/bin/env bash
# EZER Docker Subdomain Deployment for benneta.mftechnologies.org
# Runs as an isolated service alongside other company web services (icaps, karibu, nexora)
set -euo pipefail

SUBDOMAIN="benneta.mftechnologies.org"
CONTAINER_NAME="benneta"
INTERNAL_PORT=8765

echo "[*] EZER Subdomain Deployment -> ${SUBDOMAIN}"

# 1. Must be root
if [ "$EUID" -ne 0 ]; then
  echo "[-] Run as root: sudo ./deploy_mftechnologies.sh"
  exit 1
fi

# 2. Rebuild and launch Docker Compose
echo "[*] Deploying EZER Docker container..."
docker compose down 2>/dev/null || true
docker compose up -d --build

# 3. Configure Apache VirtualHost strictly for the subdomain
echo "[*] Installing Apache subdomain config for ${SUBDOMAIN}..."
cp benneta-apache.conf /etc/apache2/sites-available/benneta.conf
a2ensite benneta.conf
apache2ctl configtest
systemctl reload apache2

echo "[+] Deployment complete!"
echo "[+] EZER Subdomain: https://${SUBDOMAIN}/"
echo "[+] Dashboard App:     https://${SUBDOMAIN}/app"
echo "[+] Downloads:         https://${SUBDOMAIN}/downloads/"
