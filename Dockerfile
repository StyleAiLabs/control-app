FROM php:8.4-cli AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        docker-compose \
        docker.io \
        git \
        unzip \
        libpq-dev \
        libsqlite3-dev \
        netcat-openbsd \
        openssh-client \
        rsync \
        sshpass \
    && docker-php-ext-install pdo_pgsql pdo_sqlite \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

FROM base AS development

FROM base AS vendor

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM node:22-bookworm-slim AS frontend

WORKDIR /var/www/html

COPY package.json package-lock.json ./

RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build

FROM base AS production

COPY . /var/www/html
COPY --from=vendor /var/www/html/vendor /var/www/html/vendor
COPY --from=frontend /var/www/html/public/build /var/www/html/public/build

RUN chmod +x \
    docker/start-app.sh \
    docker/start-worker.sh \
    docker/start-prod-app.sh \
    docker/start-prod-worker.sh \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi
