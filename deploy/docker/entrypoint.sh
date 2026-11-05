#!/bin/sh
# Prepares the storage volume and Laravel caches, then runs the container's command
# (php-fpm, the queue worker or the scheduler).
set -e
cd /var/www/html
mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
if [ "${QSA_SKIP_OPTIMIZE:-0}" != "1" ]; then
    php artisan optimize --quiet
fi
exec "$@"
