# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base: PHP with the extensions every stage needs.
# ---------------------------------------------------------------------------
FROM php:8.5-fpm AS base

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# intl:          number and date formatting
# opcache:       compiled scripts stay in memory
# opentelemetry: auto-instrumentation hooks for distributed tracing
# pcntl:         graceful worker shutdown (Horizon, queue workers, Reverb)
# pdo_pgsql:     PostgreSQL
# redis:         phpredis client for queue, cache, rate limiter and circuit breaker
# zip:           Composer archives
RUN install-php-extensions \
        intl \
        opcache \
        opentelemetry \
        pcntl \
        pdo_pgsql \
        redis \
        zip

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor: production dependencies only.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

# ---------------------------------------------------------------------------
# assets: the frontend build. The Wayfinder Vite plugin runs
# "php artisan wayfinder:generate", so Node runs next to PHP and the app.
# The Reverb address is baked into the bundle, hence the build arguments.
# ---------------------------------------------------------------------------
FROM vendor AS assets

COPY --from=node:22.23.5-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22.23.5-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

ARG VITE_APP_NAME=EDNS
ARG VITE_REVERB_APP_KEY=edns-local-key
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
# The autoload dump also discovers the packages, so artisan knows wayfinder:generate.
RUN composer dump-autoload --no-dev \
    && npm run build

# ---------------------------------------------------------------------------
# app: the runtime image for php-fpm, Horizon, the scheduler and Reverb.
# ---------------------------------------------------------------------------
FROM base AS app

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-fpm.d/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf
COPY docker/entrypoint.sh docker/init.sh /usr/local/bin/

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /var/www/html/public/build ./public/build

# Package discovery runs as part of the autoload dump; it needs no database.
# Settings come from the environment; the empty .env only spares Laravel a failed read.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && touch .env \
    && mkdir -p /var/www/secrets \
    && chown -R www-data:www-data storage bootstrap/cache /var/www/secrets

USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# web: nginx serving the public directory and passing PHP to the app service.
# ---------------------------------------------------------------------------
FROM nginx:1.31.8-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

# ---------------------------------------------------------------------------
# tests: the app with development dependencies, to run the test suite.
# ---------------------------------------------------------------------------
FROM base AS tests

# The whole suite runs in one process; PHP's default 128M is not enough.
RUN echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/zz-tests.ini

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
# Settings come from the environment and phpunit.xml; the empty .env only spares Laravel a failed read.
RUN composer dump-autoload --optimize \
    && touch .env

CMD ["sh", "-c", "APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));') php artisan test --compact"]
