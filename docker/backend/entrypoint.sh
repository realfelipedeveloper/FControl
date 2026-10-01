#!/bin/sh
set -eu
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
php artisan config:cache
if [ "${1:-}" = "php-fpm" ]; then
  attempt=1
  until php artisan migrate --force; do
    if [ "$attempt" -ge 15 ]; then
      echo "Banco indisponível após $attempt tentativas." >&2
      exit 1
    fi
    echo "Banco ainda indisponível; nova tentativa em 2 segundos ($attempt/15)." >&2
    attempt=$((attempt + 1))
    sleep 2
  done
fi
exec "$@"
