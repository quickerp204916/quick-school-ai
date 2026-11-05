#!/usr/bin/env bash
# Restore a backup made by backup.sh. Replaces the current databases and documents.
#   sudo ./deploy/restore.sh /var/backups/quick-school-ai/20261106T210000Z
set -euo pipefail
source "$(dirname "$0")/lib.sh"

SRC="${1:-}"
[ -d "$SRC" ] || die "Usage: restore.sh <backup folder>"
( cd "$SRC" && sha256sum -c --quiet SHA256SUMS ) || die "Checksum mismatch in $SRC"

read -r -p "This replaces ALL current data with the backup from $(basename "$SRC"). Type RESTORE to continue: " answer
[ "$answer" = "RESTORE" ] || die "Cancelled."

log "Stopping the app"
dc stop caddy app queue scheduler ai-service

for pair in quick_school_ai:qsa_app qsa_ai:qsa_ai; do
    db="${pair%%:*}" owner="${pair##*:}"
    log "Restoring $db"
    dc exec -T postgres psql -U postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS $db WITH (FORCE)" -c "CREATE DATABASE $db OWNER $owner"
    # As the superuser, keeping the original owners (qsa_app / qsa_ai) recorded in the dump.
    dc exec -T postgres pg_restore -U postgres -d "$db" --exit-on-error < "$SRC/$db.dump"
done

log "Restoring documents"
dc run --rm --no-deps -T -e QSA_SKIP_OPTIMIZE=1 app sh -c 'rm -rf /var/www/html/storage/app && tar -C /var/www/html/storage -xzf -' < "$SRC/storage-app.tar.gz"

log "Starting the app"
dc up -d
log "Restore complete."
