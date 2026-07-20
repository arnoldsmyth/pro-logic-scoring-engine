#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one (php artisan key:generate --show) and set it in Coolify's env vars — it must stay stable across deploys/restarts." >&2
  exit 1
fi

echo "Waiting for the database..."
attempt=0
until php artisan db:show >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo "Database did not become reachable in time." >&2
    exit 1
  fi
  sleep 2
done

echo "Running migrations..."
php artisan migrate --force

echo "Starting FrankenPHP on port ${PORT:-80}..."
exec frankenphp php-server --listen ":${PORT:-80}" --root public/
