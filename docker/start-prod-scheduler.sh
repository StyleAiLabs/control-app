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
  echo "Scheduler: waiting for PostgreSQL..."
  sleep 1
done

until nc -z "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}"; do
  echo "Scheduler: waiting for Redis..."
  sleep 1
done

# Wait for the app to be up (migrations must have run first)
until php -r "exit(@file_get_contents('http://app:8000/up') === false ? 1 : 0);"; do
  echo "Scheduler: waiting for app health check..."
  sleep 2
done

php artisan optimize

echo "Scheduler: starting schedule:work"
exec php artisan schedule:work
