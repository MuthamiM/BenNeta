#!/usr/bin/env sh
set -e

DOMAIN="${BENNETA_DOMAIN:-mftechnologies.org}"

# Initialize config if not present
if [ ! -f /app/config/config.json ]; then
    cp /app/config/config.example.json /app/config/config.json
fi

# Initialize database schema if not present
if [ ! -f /app/data/assistant.db ]; then
    python3 /app/python/db.py 2>/dev/null || true
fi

echo "[*] BenNeta Microservices Container — Production"
echo "[*] Domain: ${DOMAIN}"
echo "[*] Memory: Strict Max 28MB RAM"
echo "[*] Routes:"
echo "[*]   Landing Page & Downloads: https://${DOMAIN}/"
echo "[*]   Assistant Dashboard:      https://${DOMAIN}/app"
echo "[*]   API Endpoint:             https://${DOMAIN}/api.php"

# Launch PHP built-in server with 24M memory limit
exec php -d memory_limit=24M -S 0.0.0.0:8765 -t /app/web /app/web/router.php
