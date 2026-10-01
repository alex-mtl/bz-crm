#!/bin/sh
set -e

cd /var/www/html

# storage/framework lives on a named volume (fast Linux FS); make sure its layout exists and is writable.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views
chown -R www-data:www-data storage/framework

# vendor/ lives on a named volume too: install it on first start (only the php-fpm container does this).
if [ "$1" = "php-fpm" ]; then
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist
    fi
else
    i=0
    until [ -f vendor/autoload.php ] || [ "$i" -ge 300 ]; do
        i=$((i + 1))
        sleep 2
    done
fi

exec docker-php-entrypoint "$@"
