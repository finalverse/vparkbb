#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

if [[ -f ".env" ]]; then
  set -a
  source ".env"
  set +a
fi

BACKUP_DIR="${BACKUP_DIR:-${PROJECT_ROOT}/backups/db}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_FILE="${BACKUP_DIR}/phpbb_${TIMESTAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

echo "[backup] creating MariaDB backup at ${BACKUP_FILE}"
docker compose exec -T db sh -lc \
  'mariadb-dump --single-transaction --quick --lock-tables=false -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  | gzip -9 > "${BACKUP_FILE}"

if [[ ! -s "${BACKUP_FILE}" ]]; then
  echo "[backup] backup file is empty; aborting"
  exit 1
fi

echo "[backup] applying retention (${RETENTION_DAYS} days)"
find "${BACKUP_DIR}" -type f -name 'phpbb_*.sql.gz' -mtime +"${RETENTION_DAYS}" -print -delete

echo "[backup] success"
