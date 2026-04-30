#!/usr/bin/env sh
set -eu

export PORT="${PORT:-8080}"

envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/sites-enabled/default

php artisan config:cache
php artisan route:cache
php artisan view:cache

php-fpm -D
exec nginx -g 'daemon off;'
