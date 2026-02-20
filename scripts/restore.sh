#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <backup.sql.gz|backup.sql>"
  exit 1
fi

BACKUP_FILE="$1"
if [[ ! -f "${BACKUP_FILE}" ]]; then
  echo "[restore] file not found: ${BACKUP_FILE}"
  exit 1
fi

if [[ -f ".env" ]]; then
  set -a
  source ".env"
  set +a
fi

echo "[restore] restoring ${BACKUP_FILE} into database ${DB_NAME:-<from-container-env>}"

if [[ "${BACKUP_FILE}" == *.gz ]]; then
  gunzip -c "${BACKUP_FILE}" | docker compose exec -T db sh -lc \
    'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
else
  cat "${BACKUP_FILE}" | docker compose exec -T db sh -lc \
    'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
fi

echo "[restore] success"
