#!/usr/bin/env bash
# Nightly backup: both databases (pg_dump custom format) and uploaded documents.
# Files go to /var/backups/quick-school-ai/<timestamp>/ and are kept for BACKUP_KEEP_DAYS days.
# If BACKUP_RCLONE_REMOTE is set, the folder is also copied off the server (strongly recommended).
set -euo pipefail
source "$(dirname "$0")/lib.sh"

ROOT=/var/backups/quick-school-ai
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="$ROOT/$STAMP"
KEEP="$(env_get BACKUP_KEEP_DAYS)"; KEEP="${KEEP:-14}"
REMOTE="$(env_get BACKUP_RCLONE_REMOTE)"

mkdir -p "$DEST"
chmod 700 "$ROOT" "$DEST"

for db in quick_school_ai qsa_ai; do
    log "Dumping $db"
    dc exec -T postgres pg_dump -U postgres -Fc "$db" > "$DEST/$db.dump"
done

log "Archiving uploaded documents"
dc exec -T app tar -C /var/www/html/storage -czf - app > "$DEST/storage-app.tar.gz"

( cd "$DEST" && sha256sum ./* > SHA256SUMS )
log "Backup written to $DEST ($(du -sh "$DEST" | cut -f1))"

if [ -n "$REMOTE" ]; then
    command -v rclone >/dev/null || die "BACKUP_RCLONE_REMOTE is set but rclone is not installed."
    log "Copying to $REMOTE/$STAMP"
    rclone copy "$DEST" "$REMOTE/$STAMP"
fi

find "$ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP" -exec rm -rf {} +
