#!/usr/bin/env bash
# Monthly restore drill (acceptance test 13): restore the newest backup into a scratch
# database, check it, drop it, and record the result. A backup that was never restored
# is not a backup.
# Cron: 30 3 1 * *  /opt/dealer-saas/deploy/restore-test.sh >> /var/log/dealer-restore-test.log 2>&1
set -euo pipefail

cd "$(dirname "$0")/.."
source .env

COMPOSE="docker compose -f deploy/docker-compose.prod.yml"
LATEST="$(ls -1t deploy/backups/*.dump | head -n1)"
SCRATCH="restore_check"

sha256sum --check "${LATEST}.sha256"

$COMPOSE exec -T postgres dropdb -U postgres --if-exists "${SCRATCH}"
$COMPOSE exec -T postgres createdb -U postgres "${SCRATCH}"
$COMPOSE exec -T postgres pg_restore -U postgres --no-owner --dbname="${SCRATCH}" < "${LATEST}"

TENANTS=$($COMPOSE exec -T postgres psql -U postgres -d "${SCRATCH}" -tAc "select count(*) from tenants")
AUDIT=$($COMPOSE exec -T postgres psql -U postgres -d "${SCRATCH}" -tAc "select count(*) from audit_logs")
RLS=$($COMPOSE exec -T postgres psql -U postgres -d "${SCRATCH}" -tAc "select count(*) from pg_class where relforcerowsecurity")

$COMPOSE exec -T postgres dropdb -U postgres "${SCRATCH}"

if [[ "${TENANTS}" -lt 1 || "${RLS}" -lt 1 ]]; then
    echo "$(date -u) RESTORE TEST FAILED: tenants=${TENANTS} rls_tables=${RLS} file=${LATEST}"
    exit 1
fi

echo "$(date -u) restore test ok: file=${LATEST} tenants=${TENANTS} audit_rows=${AUDIT} rls_tables=${RLS}"
