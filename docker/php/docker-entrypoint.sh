#!/bin/sh
set -e

if [ ! -f /var/www/html/vendor/autoload_runtime.php ]; then
    cp -a /var/www/html-image/vendor/. /var/www/html/vendor/
    chown -R www-data:www-data /var/www/html/vendor
fi

crond -b -l 8

exec docker-php-entrypoint "$@"
