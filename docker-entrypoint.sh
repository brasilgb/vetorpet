#!/bin/sh
set -eu
# The public upload directory is a bind mount: image-time ownership does not apply.
mkdir -p /var/www/html/storage/app/public/products
chown www-data:www-data /var/www/html/storage/app/public /var/www/html/storage/app/public/products
mkdir -p /var/www/html/public/build
cp -a /opt/public-build/. /var/www/html/public/build/
exec "$@"
