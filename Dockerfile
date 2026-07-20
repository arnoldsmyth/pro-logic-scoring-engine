# syntax=docker/dockerfile:1

# --- Stage 1: build the panel SPA -------------------------------------------
# vite.config.ts's build.outDir (../api/public/panel-assets) is relative to
# panel/, so both directories must exist in the same build context for the
# output to land in the right place — hence copying the whole repo here
# rather than just panel/.
FROM node:22-alpine AS panel-build
WORKDIR /app
COPY panel/package.json panel/package-lock.json ./panel/
RUN cd panel && npm ci
COPY api/public ./api/public
COPY panel ./panel
RUN cd panel && npm run build

# --- Stage 2: PHP deps (composer) -------------------------------------------
FROM composer:2 AS composer-build
WORKDIR /app
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader --ignore-platform-reqs

# --- Stage 3: runtime --------------------------------------------------------
FROM dunglas/frankenphp:1-php8.3-alpine

RUN install-php-extensions pdo_mysql pcntl opcache intl

WORKDIR /app

COPY api/ ./
COPY --from=composer-build /app/vendor ./vendor
COPY --from=panel-build /app/api/public/panel-assets ./public/panel-assets

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV PORT=80
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
