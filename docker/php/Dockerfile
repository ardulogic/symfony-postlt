# ---------- BASE (common bits) ----------
FROM php:8.3-fpm-alpine AS base

# System + runtime libs, then build deps
RUN apk add --no-cache git curl bash icu-libs postgresql-libs \
 && apk add --no-cache --virtual .build-deps autoconf build-base icu-dev libpq-dev oniguruma-dev

# PHP extensions
RUN docker-php-ext-install -j"$(nproc)" intl bcmath pcntl opcache pdo pdo_pgsql \
 && pecl install redis \
 && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# ---------- PROD ----------
FROM base AS prod

# App (prod: vendor --no-dev, warm cache)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-dev --no-progress --optimize-autoloader --no-scripts
COPY . .

# PHP tuning (prod)
RUN { \
  echo "opcache.enable=1"; \
  echo "opcache.enable_cli=0"; \
  echo "opcache.validate_timestamps=0"; \
  echo "opcache.memory_consumption=256"; \
  echo "opcache.interned_strings_buffer=16"; \
  echo "opcache.max_accelerated_files=20000"; \
  echo "opcache.jit=tracing"; \
  echo "opcache.jit_buffer_size=64M"; \
} > /usr/local/etc/php/conf.d/opcache.ini \
 && { \
  echo "memory_limit=256M"; \
  echo "expose_php=0"; \
  echo "session.use_strict_mode=1"; \
  echo "date.timezone=UTC"; \
} > /usr/local/etc/php/conf.d/custom.ini

# Slim final image (keep runtime libs)
RUN apk del .build-deps && rm -rf /tmp/* /var/cache/apk/*

# Non-root user + composer home
RUN addgroup -g 1000 -S www \
 && adduser -S -D -H -G www -u 1000 www -h /home/www \
 && mkdir -p /home/www/.composer /app/var \
 && chown -R www:www /home/www /app
ENV COMPOSER_HOME=/home/www/.composer
USER www

RUN php bin/console cache:clear --no-warmup && php bin/console cache:warmup

CMD ["php-fpm", "-F"]

# ---------- DEV ----------
FROM base AS dev

# Xdebug install + enable
RUN apk add --no-cache --virtual .xdebug-build $PHPIZE_DEPS linux-headers \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 # remove any stray/bad ini from previous attempts
 && rm -f /usr/local/etc/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini \
 && apk del .xdebug-build || true

# Dev settings
RUN { \
  echo "opcache.enable=0"; \
  echo "opcache.validate_timestamps=1"; \
  echo "opcache.revalidate_freq=0"; \
  echo "memory_limit=512M"; \
} > /usr/local/etc/php/conf.d/dev.ini \
 && { \
  echo "xdebug.mode=debug,develop"; \
  echo "xdebug.start_with_request=yes"; \
  echo "xdebug.client_port=9003"; \
  echo "xdebug.client_host=${XDEBUG_CLIENT_HOST:-host.docker.internal}"; \
} > /usr/local/etc/php/conf.d/99-xdebug.ini

WORKDIR /app

# 🔑 Do NOT run composer install in the dev image build.
# Your dev compose will bind-mount ./app -> /app, so any built /app/vendor
# would be hidden anyway. We'll run composer after the mount.

# Non-root user + composer home (so composer cache is writable)
RUN addgroup -g 1000 -S www \
 && adduser -S -D -H -G www -u 1000 www -h /home/www \
 && mkdir -p /home/www/.composer /app/var \
 && chown -R www:www /home/www /app
ENV COMPOSER_HOME=/home/www/.composer
USER www

# Run composer install only if needed
# ENTRYPOINT ["/entrypoint.dev.sh"]
CMD ["php-fpm", "-F"]

