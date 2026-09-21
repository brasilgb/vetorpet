#!/bin/sh
set -eu
mkdir -p /var/www/html/public/build
cp -a /opt/public-build/. /var/www/html/public/build/
exec "$@"
