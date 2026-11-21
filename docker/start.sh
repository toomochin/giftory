#!/bin/sh
set -eu

key_file=/var/lib/giftory/app.key
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$key_file" ]; then
        umask 077
        php artisan key:generate --show --no-ansi > "$key_file.tmp"
        mv "$key_file.tmp" "$key_file"
    fi
    APP_KEY=$(cat "$key_file")
    export APP_KEY
fi

touch "$DB_DATABASE"
php artisan migrate --force --no-interaction
exec "$@"
