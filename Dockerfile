# syntax=docker/dockerfile:1

FROM php:8.5-fpm

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# opentelemetry: auto-instrumentation hooks for distributed tracing
# pcntl:         graceful worker shutdown (Horizon, queue workers, Reverb)
# pdo_pgsql:     PostgreSQL
# redis:         phpredis client for queue, cache and rate limiter
RUN install-php-extensions \
        intl \
        opentelemetry \
        pcntl \
        pdo_pgsql \
        redis \
        zip

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache
