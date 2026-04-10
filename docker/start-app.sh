#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

mkdir -p \
  runtime/tenants \
  templates/tenant \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/testing \
  storage/framework/views \
  bootstrap/cache

until nc -z "${DB_HOST:-postgres}" "${DB_PORT:-5432}"; do
  echo "Waiting for PostgreSQL..."
  sleep 1
done

until nc -z "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}"; do
  echo "Waiting for Redis..."
  sleep 1
done

php artisan optimize:clear
php artisan migrate --seed --force
php artisan serve --host=0.0.0.0 --port=8000
