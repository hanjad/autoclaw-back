# syntax=docker/dockerfile:1
#
# Autoclaw API (Laravel 12) for Render: PHP 8.3 + Apache, one container.
# Build:  docker build -t autoclaw-api .
# Run:    docker run --rm -p 10000:10000 --env-file .env.docker autoclaw-api

# ---------------------------------------------------------------- base: PHP + extensions + Apache
FROM php:8.3-apache-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql bcmath intl zip gd opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Apache listens on $PORT (Render sets it; 10000 is its default) and serves Laravel's public/ folder
ENV PORT=10000
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache-extra.conf /etc/apache2/conf-available/zz-extra.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
RUN a2enconf zz-extra

# ---------------------------------------------------------------- vendor: Composer dependencies
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Install packages first so this layer is cached until composer.lock changes
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------------------------------------------------------------- final image
FROM base AS app
WORKDIR /var/www/html
COPY --from=vendor /var/www/html /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
                storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 10000

# The entrypoint caches config, runs migrations, then starts Apache
ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
