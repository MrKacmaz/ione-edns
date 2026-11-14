#!/bin/sh
# Entrypoint of every PHP service (app, horizon, scheduler, reverb, init).
#
# Secrets are not baked into the image or committed: the init service creates
# them once in the shared "secrets" volume, and each service reads them here
# unless they are already set in the environment.
set -e

SECRETS_DIR=/var/www/secrets

if [ -z "${APP_KEY:-}" ] && [ -f "$SECRETS_DIR/app_key" ]; then
    APP_KEY="$(cat "$SECRETS_DIR/app_key")"
    export APP_KEY
fi

if [ -z "${REVERB_APP_SECRET:-}" ] && [ -f "$SECRETS_DIR/reverb_app_secret" ]; then
    REVERB_APP_SECRET="$(cat "$SECRETS_DIR/reverb_app_secret")"
    export REVERB_APP_SECRET
fi

# Cache the configuration, routes, events and views once per start. The cache is
# built from the environment and the secrets above, and lives in this container only.
# Not for init, which creates the secrets in the first place.
if [ "${1:-}" != "init.sh" ]; then
    php artisan optimize --quiet
fi

exec "$@"
