# FrankenPHP (Caddy + PHP 8.4) serving Laravel.
# Targets: "app" = production image (web, queue workers, scheduler); "dev" = local development
# (docker-compose.yml), code mounted from the host.
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions pdo_pgsql pgsql intl bcmath gd zip redis pcntl opcache exif

# OCR for scanned documents (DE/FR/IT/EN) and PDF tools (text extraction, page rendering).
RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-deu tesseract-ocr-fra tesseract-ocr-ita poppler-utils \
    && rm -rf /var/lib/apt/lists/*

ENV SERVER_NAME=":80"

WORKDIR /app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Local development: APP_ENV etc. come from the mounted .env, code changes are picked up
# without a restart, and composer is available (vendor lives in a Docker volume, see compose file).
FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base AS app
ENV APP_ENV=production \
    APP_DEBUG=false
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80 443
