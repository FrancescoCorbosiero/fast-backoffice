# Development image: PHP 8.4 CLI with the extensions the app needs and Composer.
FROM php:8.4-cli

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions intl pdo_pgsql pgsql redis gd zip bcmath pcntl opcache @composer \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

EXPOSE 8000
