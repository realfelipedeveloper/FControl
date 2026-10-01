FROM composer:2.9 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader
COPY backend .
RUN composer dump-autoload --no-dev --optimize --no-interaction

FROM php:8.5-fpm-alpine
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j$(nproc) bcmath pdo_mysql \
    && apk del .build-deps
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app .
COPY docker/backend/entrypoint.sh /usr/local/bin/fcontrol-entrypoint
RUN chmod +x /usr/local/bin/fcontrol-entrypoint \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["fcontrol-entrypoint"]
CMD ["php-fpm"]
