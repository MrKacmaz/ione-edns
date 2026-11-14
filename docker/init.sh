#!/bin/sh
# One-off setup that every other PHP service waits for:
# 1. creates the secrets on the first start (kept in the "secrets" volume),
# 2. runs the migrations,
# 3. seeds the operator account and demo templates (safe to run again).
set -e

SECRETS_DIR=/var/www/secrets

if [ ! -s "$SECRETS_DIR/app_key" ]; then
    php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$SECRETS_DIR/app_key"
    echo "Created APP_KEY."
fi

if [ ! -s "$SECRETS_DIR/reverb_app_secret" ]; then
    php -r 'echo bin2hex(random_bytes(20));' > "$SECRETS_DIR/reverb_app_secret"
    echo "Created REVERB_APP_SECRET."
fi

APP_KEY="$(cat "$SECRETS_DIR/app_key")"
REVERB_APP_SECRET="$(cat "$SECRETS_DIR/reverb_app_secret")"
export APP_KEY REVERB_APP_SECRET

php artisan migrate --force
php artisan db:seed --force
