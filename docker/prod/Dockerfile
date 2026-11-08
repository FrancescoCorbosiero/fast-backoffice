# Production images (design.md §7): one build, three targets.
#
#   docker build --target app -t fast-backoffice/app:latest -f docker/prod/Dockerfile .
#   docker build --target worker -t fast-backoffice/worker:latest -f docker/prod/Dockerfile .
#   docker build --target web -t fast-backoffice/web:latest -f docker/prod/Dockerfile .
#
# "app" is PHP-FPM with the application and its production dependencies;
# the same image runs Horizon and the scheduler with another command.
# "worker" is "app" plus git, Node and the Claude Code CLI: the pipeline
# worker (docs/pipeline.md §3), the only consumer of the pipeline queue.
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
# storage/app/pipeline-uploads exists, owned by www-data, so the pipeline_uploads
# volume starts with that owner whichever container mounts it first.
RUN php artisan package:discover --ansi \
    && php artisan filament:assets --ansi \
    && php artisan filament:upgrade --ansi \
    && rm -rf tests docker docs .git .github \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache storage/app/pipeline-uploads \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=0755 docker/prod/entrypoint.sh /usr/local/bin/entrypoint

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- 3. The pipeline worker: git, Node, the Claude Code CLI -----------------
FROM app AS worker

USER root

# git and ssh for the clients' repositories, Node for static-site builds and
# the CLI, bash for the CLI's Bash tool (it looks for SHELL, which the CLI
# alone is given as /bin/bash), a writable home for the CLI's own files. Add Hugo or other
# generators here as the sites need them.
RUN apk add --no-cache bash git openssh-client nodejs npm \
    && npm install -g @anthropic-ai/claude-code \
    && npm cache clean --force \
    && mkdir -p /home/www-data /var/www/html/storage/app/pipeline /var/www/html/storage/app/previews \
    && chown -R www-data:www-data /home/www-data /var/www/html/storage/app

ENV HOME=/home/www-data
USER www-data
CMD ["php", "artisan", "queue:work", "redis-pipeline", "--queue=pipeline", "--timeout=3900", "--sleep=3"]

# --- 4. Caddy with the public directory -------------------------------------
FROM caddy:2-alpine AS web

COPY docker/prod/Caddyfile /etc/caddy/Caddyfile
# The same path as in the app image: FastCGI hands FPM the script's path as web sees it.
COPY --from=app /var/www/html/public /var/www/html/public
