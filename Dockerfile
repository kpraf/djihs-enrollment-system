# syntax=docker/dockerfile:1

# ---- 1. PHP dependencies ----------------------------------------------------
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- 2. Frontend build ------------------------------------------------------
# Ziggy's route() helper is imported from vendor/, so this stage needs it.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- 3. Runtime -------------------------------------------------------------
FROM php:8.3-apache AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libpng-dev libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip gd intl opcache \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers \
    && sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
RUN rm -rf legacy tests node_modules \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private \
    && chown -R www-data:www-data storage bootstrap/cache

ENV PORT=10000 \
    APP_ENV=production \
    LOG_CHANNEL=stderr

EXPOSE 10000
COPY docker/start.sh /usr/local/bin/start
RUN chmod +x /usr/local/bin/start
CMD ["start"]
