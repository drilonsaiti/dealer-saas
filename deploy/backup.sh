#!/usr/bin/env bash
# Nightly database backup: dump -> compress -> copy to off-site Swiss object storage -> prune.
# Run from cron on the VPS, e.g.:  15 2 * * *  /opt/dealer-saas/deploy/backup.sh >> /var/log/dealer-backup.log 2>&1
#
# IMPORTANT: the dump runs as the PostgreSQL superuser. Dumping as the app role would
# silently skip rows hidden by Row-Level Security (every tenant's data).
#
# Requires: rclone with a remote named in $BACKUP_REMOTE (e.g. "offsite:dealer-backups").
set -euo pipefail

cd "$(dirname "$0")/.."
source .env

COMPOSE="docker compose -f deploy/docker-compose.prod.yml"
STAMP="$(date -u +%Y-%m-%dT%H%M%SZ)"
FILE="deploy/backups/${DB_DATABASE}-${STAMP}.dump"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-30}"

mkdir -p deploy/backups

$COMPOSE exec -T postgres pg_dump -U postgres --format=custom --compress=9 "${DB_DATABASE}" > "${FILE}"
sha256sum "${FILE}" > "${FILE}.sha256"

rclone copy "${FILE}" "${BACKUP_REMOTE}/db/"
rclone copy "${FILE}.sha256" "${BACKUP_REMOTE}/db/"

# Documents live in object storage already; mirror the bucket to the off-site remote too.
if [[ -n "${BACKUP_FILES_SOURCE:-}" ]]; then
    rclone sync "${BACKUP_FILES_SOURCE}" "${BACKUP_REMOTE}/files/" --checksum
fi

find deploy/backups -name "*.dump*" -mtime +7 -delete
rclone delete "${BACKUP_REMOTE}/db/" --min-age "${KEEP_DAYS}d"

echo "$(date -u) backup ok: ${FILE}"
