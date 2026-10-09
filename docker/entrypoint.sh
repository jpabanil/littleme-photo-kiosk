#!/bin/sh
set -e
cd /app
php artisan config:cache
php artisan littleme:indexes
exec frankenphp run --config /etc/caddy/Caddyfile
