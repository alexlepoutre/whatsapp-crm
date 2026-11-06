FROM php:8.4-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN echo "opcache.memory_consumption=128" > /usr/local/etc/php/conf.d/opcache-custom.ini \
    && echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/opcache-custom.ini

WORKDIR /var/www/app
