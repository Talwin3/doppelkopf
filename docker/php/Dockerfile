FROM dunglas/frankenphp:php8.4-alpine AS base

RUN apk add --no-cache git unzip postgresql-client

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    sodium

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── Entwicklung ──────────────────────────────────────────────────────────────
FROM base AS dev

RUN install-php-extensions xdebug

ENV APP_ENV=dev
ENV APP_DEBUG=1

COPY Caddyfile /etc/frankenphp/Caddyfile

# ── Produktion ────────────────────────────────────────────────────────────────
FROM base AS prod

ENV APP_ENV=prod
ENV APP_DEBUG=0

COPY Caddyfile /etc/frankenphp/Caddyfile

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize \
 && php bin/console cache:warmup
