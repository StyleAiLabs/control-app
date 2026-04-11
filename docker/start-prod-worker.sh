#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p \
  runtime/tenants \
  templates/tenant \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/testing \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

until nc -z "${DB_HOST:-postgres}" "${DB_PORT:-5432}"; do
  echo "Waiting for PostgreSQL..."
  sleep 1
done

until nc -z "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}"; do
  echo "Waiting for Redis..."
  sleep 1
done

until php -r "exit(@file_get_contents('http://app:8000/up') === false ? 1 : 0);"; do
  echo "Waiting for app health check..."
  sleep 1
done

php artisan optimize

exec php artisan queue:work redis \
  --tries="${SYNC360_QUEUE_TRIES:-1}" \
  --timeout="${SYNC360_QUEUE_TIMEOUT:-180}" \
  --sleep="${SYNC360_QUEUE_SLEEP:-1}" \
  --max-time="${SYNC360_QUEUE_MAX_TIME:-3600}"
