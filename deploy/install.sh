#!/usr/bin/env bash
# One-time server setup for Quick School AI on a fresh Ubuntu 24.04 server (run as root,
# from a clone of the repository, e.g. /opt/quick-school-ai):
#
#   sudo ./deploy/install.sh --domain quickschool.app --email ops@example.com
#
# Installs Docker and the firewall, writes deploy/.env with generated secrets, builds and starts
# the stack, loads the subscription plans and schedules nightly backups. Safe to re-run.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

DOMAIN="" EMAIL="" DEPLOY_USER="deploy"
while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --email) EMAIL="$2"; shift 2 ;;
        --deploy-user) DEPLOY_USER="$2"; shift 2 ;;
        *) die "Unknown option $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "Run as root (sudo)."
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || log "Warning: tested on Ubuntu 24.04; this is ${PRETTY_NAME:-unknown}."

log "Installing base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q ca-certificates curl git ufw openssl unattended-upgrades

if ! command -v docker >/dev/null 2>&1; then
    log "Installing Docker Engine"
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -q
    apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
systemctl enable --now docker

log "Configuring the firewall (SSH, HTTP, HTTPS only)"
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 443/udp >/dev/null
ufw --force enable >/dev/null

if [ "$(swapon --show | wc -l)" -eq 0 ] && [ ! -f /swapfile ]; then
    log "Adding 2 GB swap"
    fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    log "Creating the '$DEPLOY_USER' user for GitHub Actions deploys"
    useradd --create-home --shell /bin/bash "$DEPLOY_USER"
fi
usermod -aG docker "$DEPLOY_USER"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
chown -R "$DEPLOY_USER:$DEPLOY_USER" "$REPO_DIR"
git config --system --add safe.directory "$REPO_DIR"

log "Writing deploy/.env"
if [ ! -f "$ENV_FILE" ]; then
    cp "$DEPLOY_DIR/.env.example" "$ENV_FILE"
fi
chmod 600 "$ENV_FILE"
[ -n "$DOMAIN" ] && env_set BASE_DOMAIN "$DOMAIN"
[ -n "$EMAIL" ] && env_set ACME_EMAIL "$EMAIL"
[ -n "$(env_get ACME_EMAIL)" ] || die "Set the Let's Encrypt e-mail: --email you@example.com"
[ -z "$(env_get APP_KEY)" ] && env_set APP_KEY "base64:$(openssl rand -base64 32)"
for key in POSTGRES_PASSWORD APP_DB_PASSWORD AI_DB_PASSWORD REDIS_PASSWORD; do
    [ -z "$(env_get "$key")" ] && env_set "$key" "$(openssl rand -hex 24)"
done
[ -z "$(env_get QSA_AI_SERVICE_KEY)" ] && env_set QSA_AI_SERVICE_KEY "$(openssl rand -hex 32)"
chown "$DEPLOY_USER:$DEPLOY_USER" "$ENV_FILE"

BASE="$(env_get BASE_DOMAIN)"
SERVER_IP="$(curl -fsS4 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"
DNS_IP="$(getent ahostsv4 "$BASE" | awk 'NR==1{print $1}')"
if [ "$DNS_IP" != "$SERVER_IP" ]; then
    log "Warning: $BASE resolves to '${DNS_IP:-nothing}', this server is $SERVER_IP."
    log "         Certificates will fail until DNS points here (A records for $BASE and *.$BASE)."
fi

log "Building and starting the stack (first build takes a few minutes)"
sudo -u "$DEPLOY_USER" "$DEPLOY_DIR/deploy.sh" --no-pull

log "Loading subscription plans"
dc exec -T app php artisan db:seed --class='Database\Seeders\PlanSeeder' --force

log "Scheduling nightly backups (02:30 IST)"
cat > /etc/cron.d/quick-school-ai <<CRON
# Quick School AI nightly backup at 21:00 UTC (02:30 IST)
0 21 * * * root $DEPLOY_DIR/backup.sh >> /var/log/quick-school-ai-backup.log 2>&1
CRON

cat <<DONE

Quick School AI is running.

  Super Admin console   https://$BASE/platform
  Institutions          https://<code>.$BASE

Next steps:
  1. Create your super admin (you'll be asked for a password; it never leaves this server):
       cd $REPO_DIR && docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env \\
         exec app php artisan platform:create-admin you@example.com "Your Name"
  2. Fill in MAIL_* (and OPENAI_API_KEY for AI) in $ENV_FILE, then run: sudo -u $DEPLOY_USER $DEPLOY_DIR/deploy.sh --no-pull
  3. For GitHub Actions deploys, add the Actions public key to /home/$DEPLOY_USER/.ssh/authorized_keys
     (see deploy/DEPLOY.md).
DONE
