# Quick School AI web app: PHP 8.4 FPM with the extensions Laravel and the ERP modules need.
# Build context is the repository root:  docker build -f deploy/docker/web.Dockerfile .
FROM php:8.4-fpm-bookworm AS base

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip git; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql intl zip gd bcmath opcache pcntl; \
    pecl install redis; docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev; \
    apt-get install -y --no-install-recommends libpq5 libicu72 libzip4 libpng16-16 libjpeg62-turbo libfreetype6; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-quick-school.ini
COPY deploy/docker/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-quick-school.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first so code changes don't reinstall them.
COPY web/composer.json web/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY web/ ./
RUN set -eux; \
    rm -f .env; \
    composer dump-autoload --optimize --classmap-authoritative --no-dev; \
    php artisan package:discover --ansi; \
    mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache

COPY deploy/docker/entrypoint.sh /usr/local/bin/qsa-entrypoint
RUN chmod +x /usr/local/bin/qsa-entrypoint

USER www-data
ENTRYPOINT ["qsa-entrypoint"]
CMD ["php-fpm"]
