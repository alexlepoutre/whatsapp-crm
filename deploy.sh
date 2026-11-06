#!/usr/bin/env bash
# À lancer sur le serveur, depuis /home/ubuntu/whatsapp-crm.
set -euo pipefail
cd "$(dirname "$0")"

compose() {
    docker compose -f compose.prod.yaml --env-file .env.prod "$@"
}

if [ ! -f .env.prod ]; then
    db_password=$(openssl rand -hex 24)
    umask 077
    sed -e "s|^APP_SECRET=.*|APP_SECRET=$(openssl rand -hex 32)|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${db_password}|" \
        -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(openssl rand -hex 24)|" \
        -e "s|<DB_PASSWORD>|${db_password}|" \
        -e "s|^WHATSAPP_VERIFY_TOKEN=.*|WHATSAPP_VERIFY_TOKEN=$(openssl rand -hex 24)|" \
        .env.prod.example > .env.prod
    echo ".env.prod créé avec des secrets générés."
fi

compose up -d --build --wait
compose exec -T php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
compose ps
