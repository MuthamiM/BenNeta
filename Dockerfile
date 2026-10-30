# BenNeta PC Intelligence & Automation Microservices
# Optimized for ultra-low memory (< 28MB RAM budget) and Alpine efficiency
FROM php:8.2-cli-alpine

# Install minimal runtime dependencies: Python3, SQLite, curl
RUN apk add --no-cache \
    python3 \
    py3-pip \
    curl \
    sqlite \
    && rm -rf /var/cache/apk/*

WORKDIR /app

# Copy application layers
COPY php/ /app/php/
COPY python/ /app/python/
COPY web/ /app/web/
COPY config/config.example.json /app/config/config.example.json

# Copy downloadable packages (.deb and .zip)
RUN mkdir -p /app/web/downloads /app/data /app/config
COPY dist/benneta_1.0.0_amd64.deb /app/web/downloads/
COPY dist/benneta_windows_x64.zip /app/web/downloads/

# Permissions
RUN chmod -R 775 /app/data /app/config /app/web/downloads

EXPOSE 8765

# Healthcheck
HEALTHCHECK --interval=20s --timeout=3s --start-period=5s --retries=3 \
  CMD curl -sf http://127.0.0.1:8765/api.php?action=status || exit 1

# Entrypoint script
COPY docker-entrypoint.sh /app/docker-entrypoint.sh
RUN chmod +x /app/docker-entrypoint.sh

ENTRYPOINT ["/app/docker-entrypoint.sh"]
