# Shared helpers for install.sh, deploy.sh and backup.sh.
# shellcheck disable=SC2034  # variables used by the scripts that source this file
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$DEPLOY_DIR")"
ENV_FILE="$DEPLOY_DIR/.env"

dc() {
    docker compose -f "$DEPLOY_DIR/docker-compose.prod.yml" --env-file "$ENV_FILE" "$@"
}

env_get() {
    # Value of KEY from deploy/.env, without surrounding quotes.
    local v
    v="$(grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2-)"
    v="${v%\"}"; v="${v#\"}"
    printf '%s' "$v"
}

env_set() {
    # Set KEY=VALUE in deploy/.env (replacing an existing line).
    local key="$1" value="$2" tmp
    tmp="$(mktemp)"
    grep -vE "^$key=" "$ENV_FILE" > "$tmp" || true
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
    cat "$tmp" > "$ENV_FILE"
    rm -f "$tmp"
}

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }
