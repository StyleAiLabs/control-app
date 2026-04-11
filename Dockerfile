FROM php:8.4-cli

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
