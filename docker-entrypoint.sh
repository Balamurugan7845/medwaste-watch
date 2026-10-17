#!/bin/sh
set -e

echo "========================================================="
echo " MedWaste Watch Container Initializing"
echo "========================================================="

# If database host is configured, wait for MySQL to become ready
if [ -n "$DB_HOST" ]; then
    DB_PORT="${DB_PORT:-3306}"
    echo "Checking connection to database at ${DB_HOST}:${DB_PORT}..."

    RETRIES=30
    until php -r "
        \$host = getenv('DB_HOST') ?: '127.0.0.1';
        \$port = getenv('DB_PORT') ?: '3306';
        \$user = getenv('DB_USERNAME') ?: 'root';
        \$pass = getenv('DB_PASSWORD') ?: '';
        try {
            new PDO(\"mysql:host=\$host;port=\$port\", \$user, \$pass, [PDO::ATTR_TIMEOUT => 2]);
            exit(0);
        } catch (Throwable \$e) {
            exit(1);
        }
    " 2>/dev/null; do
        RETRIES=$((RETRIES - 1))
        if [ "$RETRIES" -le 0 ]; then
            echo "[WARNING] Database connection timeout exceeded. Proceeding anyway..."
            break
        fi
        echo "Waiting for database service to be ready... (${RETRIES} attempts remaining)"
        sleep 2
    done

    if [ "$RETRIES" -gt 0 ]; then
        echo "[OK] Connected to database server successfully."

        # Automatically run migrations and initial seed data if requested
        if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
            echo "Running database migrations and seeders..."
            php backend/database/migrate.php || echo "[WARNING] Migration exited with a non-zero status."
        fi
    fi
fi

# Ensure storage directories exist and have proper permissions
mkdir -p backend/storage/logs backend/storage/exports backend/storage/private
chmod -R 777 backend/storage 2>/dev/null || true

echo "Starting server on http://0.0.0.0:8000..."
exec "$@"
