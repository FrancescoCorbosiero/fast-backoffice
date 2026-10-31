# Production images (design.md §7): one build, two targets.
#
#   docker build --target app -t fast-backoffice/app:latest -f docker/prod/Dockerfile .
#   docker build --target web -t fast-backoffice/web:latest -f docker/prod/Dockerfile .
#
# "app" is PHP-FPM with the application and its production dependencies;
# the same image runs Horizon and the scheduler with another command.
# "web" is Caddy with the public directory (Filament's published assets
# included), talking to app over FastCGI. Nothing from require-dev is
# installed, no .env is baked in: configuration comes from the environment.

# --- 1. Dependencies -------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

# --- 2. The application, with Filament's assets published ------------------
FROM php:8.4-fpm-alpine AS app

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions intl pdo_pgsql pgsql redis gd zip bcmath pcntl opcache \
    && apk add --no-cache postgresql18-client \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/prod/php.ini "$PHP_INI_DIR/conf.d/99-fast-backoffice.ini"
COPY docker/prod/www.conf /usr/local/etc/php-fpm.d/zz-fast-backoffice.conf

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /build/vendor ./vendor

# Scripts were skipped in the vendor stage (they need the app): run the ones that matter here.
RUN php artisan package:discover --ansi \
    && php artisan filament:assets --ansi \
    && php artisan filament:upgrade --ansi \
    && rm -rf tests docker docs .git .github \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=0755 docker/prod/entrypoint.sh /usr/local/bin/entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- 3. Caddy with the public directory -------------------------------------
FROM caddy:2-alpine AS web

COPY docker/prod/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/html/public /srv/public
