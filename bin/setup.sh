#!/usr/bin/env bash
# One-time server-side setup for tx-bookings. Idempotent: safe to re-run.
#
# Creates the pieces docker-compose.yml expects to already exist -- the two
# external storage volumes -- and verifies everything else the stack depends on
# before the first deploy, so a missing prerequisite surfaces here rather than
# as a half-started stack.
#
# Deliberately does NOT create the MySQL database or the .env.docker file:
# both need credentials, and prompting for secrets in a script is how they end
# up in shell history. It tells you what is missing instead.
set -euo pipefail

cd "$(dirname "$0")/.."

APP_SLUG=${APP_SLUG:-tx-bookings}
fail=0

log()  { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
ok()   { printf '  \033[32mok\033[0m    %s\n' "$1"; }
warn() { printf '  \033[33mtodo\033[0m  %s\n' "$1"; fail=1; }

log "Storage volumes"
# External so that `docker compose down -v` cannot take the team logos with it.
# Compose will not create an external volume itself -- it errors out.
for vol in storage-app storage-logs; do
    name="${APP_SLUG}_${vol}"

    if docker volume inspect "$name" >/dev/null 2>&1; then
        ok "$name exists"
    else
        docker volume create "$name" >/dev/null
        ok "$name created"
    fi
done

log "Shared networks"
# Created by the platform stack, not by this one.
for net in edge platform; do
    if docker network inspect "$net" >/dev/null 2>&1; then
        ok "$net exists"
    else
        warn "$net is missing -- it belongs to the shared platform stack"
    fi
done

log "Host services"
if [[ -S /var/run/mysqld/mysqld.sock ]]; then
    ok "mysqld socket present"
else
    warn "/var/run/mysqld/mysqld.sock not found -- check that mysql is running"
fi

log "Configuration"
if [[ -f .env ]]; then
    ok ".env present (compose interpolation)"
else
    warn ".env missing. Create it with:
          APP_SLUG=${APP_SLUG}
          APP_HOST=scheduling.texasrenters.com
          APP_NAME=TR Scheduling
          HTTP_PORT=8084
          APP_VERSION=latest
          COMPOSE_FILE=docker-compose.yml"
fi

if [[ -f .env.docker ]]; then
    ok ".env.docker present"

    # A blank APP_KEY boots fine and then fails on the first encrypted cookie,
    # which reads as a broken session rather than a missing key.
    if grep -qE '^APP_KEY=.+' .env.docker; then
        ok "APP_KEY is set"
    else
        warn "APP_KEY is empty -- generate with: php artisan key:generate --show"
    fi
else
    warn "cp .env.docker.example .env.docker, then fill in the secrets"
fi

log "Database"
db=$(grep -E '^DB_DATABASE=' .env.docker 2>/dev/null | cut -d= -f2- || true)
db=${db:-tx_bookings}

if mysql -e "USE \`${db}\`" >/dev/null 2>&1; then
    ok "database ${db} exists"
else
    warn "database ${db} does not exist. Create it and a user that can reach it
          over the socket, then put the credentials in .env.docker."
fi

if (( fail )); then
    printf '\n\033[33mSetup incomplete -- resolve the items above, then re-run.\033[0m\n'
    exit 1
fi

printf '\n\033[32mReady. Run bin/deploy.sh to build and start the stack.\033[0m\n'
