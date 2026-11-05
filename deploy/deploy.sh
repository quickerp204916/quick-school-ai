#!/usr/bin/env bash
# Build and roll out the current code. Run as the deploy user (in the docker group):
#   ./deploy/deploy.sh             git pull, then deploy
#   ./deploy/deploy.sh --no-pull   deploy what is checked out (used by GitHub Actions)
set -euo pipefail
source "$(dirname "$0")/lib.sh"

PULL=1
[ "${1:-}" = "--no-pull" ] && PULL=0
[ -f "$ENV_FILE" ] || die "deploy/.env is missing. Run deploy/install.sh first."

cd "$REPO_DIR"
if [ "$PULL" -eq 1 ]; then
    log "Pulling the latest code"
    git pull --ff-only
fi
log "Deploying $(git rev-parse --short HEAD 2>/dev/null || echo 'working tree')"

log "Building images"
dc build --pull

log "Starting the database and Redis"
dc up -d --wait postgres redis

log "Running database migrations"
dc run --rm --no-deps -e QSA_SKIP_OPTIMIZE=1 app php artisan migrate --force

log "Starting the new version"
dc up -d --remove-orphans --wait app queue scheduler ai-service
dc up -d --remove-orphans caddy

docker image prune -f >/dev/null

BASE="$(env_get BASE_DOMAIN)"
log "Health check"
for _ in $(seq 1 20); do
    if dc exec -T caddy wget -qO- http://127.0.0.1:8081/up >/dev/null 2>&1; then
        log "Healthy. Live at https://$BASE"
        exit 0
    fi
    sleep 3
done
dc ps
die "The app did not answer on /up. Check: docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env logs app"
