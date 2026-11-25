#!/bin/sh
set -eu

: "${APP_KEY:?Set APP_KEY for production}"
php artisan migrate --force --no-interaction
exec "$@"
