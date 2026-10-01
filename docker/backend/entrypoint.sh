#!/bin/sh
set -eu
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
php artisan config:cache
if [ "${1:-}" = "php-fpm" ]; then
  php artisan migrate --force
fi
exec "$@"
