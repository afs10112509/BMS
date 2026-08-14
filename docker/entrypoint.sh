#!/bin/sh
set -e

cd /var/www/html

# Only the php-fpm role installs deps (avoid race with queue container)
if [ "$1" = "php-fpm" ] || [ -z "$1" ]; then
  if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction
  fi
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

if [ ! -L public/storage ] && [ -f artisan ]; then
  php artisan storage:link 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
