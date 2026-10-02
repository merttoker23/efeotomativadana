#!/usr/bin/env bash
set -euo pipefail

export APP_ENV=prod
export APP_DEBUG=0
export APP_SECRET="${APP_SECRET:?APP_SECRET is required}"

# FrankenPHP's inherited CMD supplies only its options; match the upstream entrypoint.
if [[ "${1:-}" == -* ]]; then
    set -- frankenphp run "$@"
fi

if [ "${1:-}" = "frankenphp" ]; then
    # Migrate before the HTTP listener starts. A failure exits without serving this release.
    php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction --allow-no-migration
    php bin/console doctrine:migrations:up-to-date --env=prod --no-debug --no-interaction
elif [[ "$*" == *messenger:consume* ]]; then
    # Workers never compete with the web migration. Compose restarts them until it succeeds.
    php bin/console doctrine:migrations:up-to-date --env=prod --no-debug --no-interaction
fi

exec docker-php-entrypoint "$@"
