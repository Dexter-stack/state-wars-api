# Production image: Laravel Octane on RoadRunner, PHP 8.3, phpredis, pcntl (Horizon).
# Same image for api, horizon, scheduler and migrations; pick with the command.
# NOTE: written for Linux deployments; not built on the Windows dev machine (no Docker there).

FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS rr

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
 && docker-php-ext-install pdo_pgsql pcntl sockets zip opcache \
 && pecl install redis && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*
RUN { echo 'opcache.enable_cli=1'; echo 'opcache.validate_timestamps=0'; echo 'memory_limit=256M'; } > /usr/local/etc/php/conf.d/statewars.ini

WORKDIR /app
COPY --from=rr /usr/bin/rr /app/rr
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENV APP_ENV=production NODE_ENV=production LOG_CHANNEL=stderr OCTANE_SERVER=roadrunner
EXPOSE 8080
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=${PORT:-8080} --workers=${OCTANE_WORKERS:-auto} --max-requests=1000 --rr-config=.rr.yaml"]
