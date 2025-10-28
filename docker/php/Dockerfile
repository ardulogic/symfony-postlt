# docker/php/Dockerfile
FROM php:8.3-fpm

# system deps
RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libzip-dev libpq-dev libonig-dev libxml2-dev \
 && docker-php-ext-install intl zip mbstring opcache \
 && docker-php-ext-install pdo pdo_pgsql

# composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
