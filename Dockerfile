# ==============================================================================
# MedWaste Watch — Dockerfile
# Smarter Medicine Management. Less Waste. Better Availability.
# ==============================================================================

FROM php:8.2-cli-alpine

# Set labels for metadata
LABEL maintainer="MedWaste Watch Team"
LABEL description="Hospital medicine inventory management and FEFO tracking system"

# Install system dependencies and PHP PDO MySQL extension
RUN apk add --no-cache \
    bash \
    curl \
    netcat-openbsd \
    mariadb-client \
    && docker-php-ext-install pdo pdo_mysql

# Set working directory
WORKDIR /var/www/html

# Create required backend storage directories
RUN mkdir -p \
    backend/storage/logs \
    backend/storage/exports \
    backend/storage/private \
    && chmod -R 777 backend/storage

# Copy application files
COPY . /var/www/html/

# Copy and setup entrypoint script (ensure Linux LF line endings)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose web server port
EXPOSE 8000

# Health check to ensure local web server is listening
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD nc -z 127.0.0.1 8000 || exit 1

# Set entrypoint and default command
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "router.php"]
