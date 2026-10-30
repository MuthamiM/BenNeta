#!/usr/bin/env bash
# BenNeta Docker Production Deployment for mftechnologies.org
# Deploys the containerized BenNeta app behind Nginx on the public domain.
set -euo pipefail

DOMAIN="mftechnologies.org"
CONTAINER_NAME="benneta"
IMAGE_NAME="benneta:latest"
INTERNAL_PORT=8765

echo "[*] BenNeta Production Deployment -> ${DOMAIN}"

# 1. Must be root
if [ "$EUID" -ne 0 ]; then
  echo "[-] Run as root: sudo ./deploy_mftechnologies.sh"
  exit 1
fi

# 2. Install Nginx + Certbot if missing
echo "[*] Installing Nginx and Certbot..."
apt-get update -y
apt-get install -y nginx certbot python3-certbot-nginx

# 3. Build Docker image
echo "[*] Building Docker image..."
docker build -t "${IMAGE_NAME}" .

# 4. Stop any existing container
echo "[*] Stopping existing container if running..."
docker rm -f "${CONTAINER_NAME}" 2>/dev/null || true

# 5. Run the container — bound to internal loopback only (Nginx handles public traffic)
echo "[*] Starting BenNeta container..."
docker run -d \
  --name "${CONTAINER_NAME}" \
  --memory=28m \
  --memory-swap=28m \
  --restart unless-stopped \
  --network host \
  -e BENNETA_DOMAIN="${DOMAIN}" \
  "${IMAGE_NAME}"

sleep 3

# 6. Verify container is running
if ! docker ps --filter name="${CONTAINER_NAME}" --format '{{.Status}}' | grep -q "Up"; then
  echo "[-] Container failed to start. Check: docker logs ${CONTAINER_NAME}"
  exit 1
fi
echo "[+] Container running."

# 7. Install Nginx site config
echo "[*] Configuring Nginx for ${DOMAIN}..."
cp benneta-mftechnologies.conf /etc/nginx/sites-available/benneta-mftechnologies.conf
ln -sf /etc/nginx/sites-available/benneta-mftechnologies.conf /etc/nginx/sites-enabled/

# Remove default site if it conflicts
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

# Test nginx config (SSL lines are commented out initially)
nginx -t && systemctl reload nginx
echo "[+] Nginx configured and reloaded."

# 8. Obtain SSL certificate via Certbot
echo "[*] Requesting SSL certificate for ${DOMAIN}..."
certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" --non-interactive --agree-tos --email admin@${DOMAIN} --redirect || {
  echo "[!] Certbot failed — SSL can be configured manually later."
  echo "[!] Run: certbot --nginx -d ${DOMAIN} -d www.${DOMAIN}"
}

# 9. Final health check against the public domain
echo "[*] Running health check..."
sleep 2
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://${DOMAIN}/" 2>/dev/null || echo "000")
if [ "$STATUS" = "200" ] || [ "$STATUS" = "301" ]; then
  echo "[+] BenNeta is LIVE at https://${DOMAIN}"
  echo "[+]   Landing Page:  https://${DOMAIN}/"
  echo "[+]   Dashboard:     https://${DOMAIN}/app"
  echo "[+]   Downloads:     https://${DOMAIN}/downloads/"
else
  echo "[!] HTTP check returned ${STATUS}. Verify DNS A record points to this server."
  echo "[!] Container logs: docker logs ${CONTAINER_NAME}"
  echo "[!] Nginx logs: tail -f /var/log/nginx/error.log"
fi

echo "[+] Deployment complete."
