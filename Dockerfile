# Production image: FrankenPHP (Caddy + PHP 8.4) serving Laravel.
# Same image runs the web server, the queue worker and the scheduler.
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions pdo_pgsql pgsql intl bcmath gd zip redis pcntl opcache exif

ENV SERVER_NAME=":80" \
    APP_ENV=production \
    APP_DEBUG=false

WORKDIR /app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base AS app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80 443
