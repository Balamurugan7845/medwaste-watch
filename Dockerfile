# ==============================================================================
# MedWaste Watch — Dockerfile
# Smarter Medicine Management. Less Waste. Better Availability.
# ==============================================================================


# MedWaste Watch — PHP 8.2 + TiDB Cloud
FROM php:8.2-cli-alpine

LABEL maintainer="MedWaste Watch Team"
LABEL description="Hospital medicine inventory and FEFO tracking system"

RUN apk add --no-cache \
    bash \
    curl \
    netcat-openbsd \
    mariadb-client \
    ca-certificates \
    && update-ca-certificates \
    && docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html

RUN mkdir -p \
    backend/storage/logs \
    backend/storage/exports \
    backend/storage/private \
    && chmod 750 backend/storage \
    && chmod 750 backend/storage/logs \
    && chmod 750 backend/storage/exports \
    && chmod 700 backend/storage/private

COPY . /var/www/html/

# Copy the TiDB CA certificate into the image.
COPY backend/certs/tidb-ca.pem /etc/ssl/certs/tidb-ca.pem

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD nc -z 127.0.0.1 "${PORT:-8000}" || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8000} router.php"]
