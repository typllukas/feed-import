#!/bin/sh
set -eu

: "${APP_KEY:?must be set: sessions and cookies are encrypted with it}"
# at start, not at build: the config cache holds the environment it is built in
php artisan config:cache --no-interaction
exec "$@"
