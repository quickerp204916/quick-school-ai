#!/usr/bin/env bash
# Starts a Quick School AI test server with a demo school, on port 8080.
#   ./deploy/test-up.sh           build (first run ~5-8 min) and start
#   ./deploy/test-up.sh --reset   wipe the test data and start fresh
# Works in GitHub Codespaces (opened automatically) or on any machine with Docker.
set -euo pipefail
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$DEPLOY_DIR/.env.test"
dc() { docker compose -f "$DEPLOY_DIR/docker-compose.test.yml" "$@"; }
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

if [ "${1:-}" = "--reset" ]; then
    log "Removing old test data"
    dc down -v --remove-orphans || true
    rm -f "$ENV_FILE"
fi

if [ -n "${CODESPACE_NAME:-}" ]; then
    URL="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
else
    URL="${TEST_URL:-http://localhost:8080}"
fi

if [ ! -f "$ENV_FILE" ]; then
    log "Generating test secrets"
    PG=$(openssl rand -hex 16) APPDB=$(openssl rand -hex 16) AIDB=$(openssl rand -hex 16)
    RD=$(openssl rand -hex 16) AIKEY=$(openssl rand -hex 32)
    ADMIN_PW="Qsa-$(openssl rand -hex 4)-Test"
    cat > "$ENV_FILE" <<ENV
APP_NAME="Quick School AI (test)"
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=$URL
SESSION_SECURE_COOKIE=$([ "${URL#https}" != "$URL" ] && echo true || echo false)
POSTGRES_PASSWORD=$PG
APP_DB_PASSWORD=$APPDB
AI_DB_PASSWORD=$AIDB
DB_PASSWORD=$APPDB
REDIS_PASSWORD=$RD
QSA_AI_SERVICE_KEY=$AIKEY
QSA_AI_DATABASE_URL=postgresql://qsa_ai:$AIDB@postgres:5432/qsa_ai
PLATFORM_ADMIN_NAME="Test Admin"
PLATFORM_ADMIN_EMAIL=admin@test.local
PLATFORM_ADMIN_PASSWORD=$ADMIN_PW
PLATFORM_SELLER_NAME="Quick ERP Technologies Pvt. Ltd."
PLATFORM_SELLER_STATE_CODE=09
OPENAI_API_KEY=
GEMINI_API_KEY=
ENV
    chmod 600 "$ENV_FILE"
fi
# Keep APP_URL in step with the current address (a Codespace can be renamed or reopened).
sed -i "s|^APP_URL=.*|APP_URL=$URL|" "$ENV_FILE"

log "Building images (the first build takes several minutes)"
dc build
log "Starting the database and Redis"
dc up -d --wait postgres redis
log "Running migrations and loading the demo school"
dc run --rm --no-deps -e QSA_SKIP_OPTIMIZE=1 app sh -c \
    'php artisan migrate --force && php artisan db:seed --force && php artisan db:seed --class=Database\\Seeders\\DemoSchoolSeeder --force'
log "Starting the app"
dc up -d --remove-orphans --force-recreate app queue scheduler ai-service web

for _ in $(seq 1 40); do
    curl -fsS -o /dev/null http://localhost:8080/up && break
    sleep 3
done

ADMIN_PW="$(grep '^PLATFORM_ADMIN_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
cat <<DONE

Quick School AI test server is running at $URL

  Demo school (Sunrise Public School)
    $URL/i/demo/login
    owner@demo.test  /  Demo-School-2026

  Super Admin console
    $URL/platform/login
    admin@test.local  /  $ADMIN_PW
    (asks you to set up an authenticator app on first sign-in)

  Logs:  docker compose -f deploy/docker-compose.test.yml logs -f app
  Stop:  docker compose -f deploy/docker-compose.test.yml down
DONE
