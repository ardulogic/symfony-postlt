#!/usr/bin/env sh
set -e

# Dev entrypoint: ensures dependencies are present and fast restarts.
# - Runs `composer install` only when needed (first run or lockfile changed).
# - Avoids baking local vendor/ into images while keeping containers runnable.
# - Speeds up dev cycles and prevents “missing vendor” errors before starting PHP-FPM.

# ensure composer home/cache is writable
: "${COMPOSER_HOME:=/home/www/.composer}"
mkdir -p "$COMPOSER_HOME"

cd /app

# install if vendor is missing OR composer.lock changed since last install
if [ ! -f vendor/autoload.php ] || [ ! -f .composer.lock.hash ] || ! cmp -s .composer.lock.hash composer.lock; then
  echo "[dev] Running composer install..."
  composer install --no-interaction --prefer-dist --optimize-autoloader
  cp composer.lock .composer.lock.hash || true
else
  echo "[dev] Dependencies up to date; skipping composer install."
fi

exec "$@"

