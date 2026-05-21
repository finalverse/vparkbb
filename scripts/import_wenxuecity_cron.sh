#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

MODE="production"
PROJECT_NAME="${COMPOSE_PROJECT_NAME:-vparkbb}"
COMPOSE_FILES=("docker-compose.yml")
LIMIT="${WENXUECITY_IMPORT_LIMIT:-20}"
FORUM="${WENXUECITY_IMPORT_FORUM:-}"
FORUM_ID="${WENXUECITY_IMPORT_FORUM_ID:-}"
DRY_RUN=0

usage() {
  cat <<'USAGE'
Usage: ./scripts/import_wenxuecity_cron.sh [options]

Options:
  --local               Use docker-compose.local.yml defaults
  --project <name>      Compose project name override
  --limit <n>           Max articles to import (default: 20 or WENXUECITY_IMPORT_LIMIT)
  --forum-id <id>       Target existing postable forum id
  --forum <name>        Target existing postable forum name
  --dry-run             Crawl and parse without writing; skips cache purge
  -h, --help            Show help

Examples:
  ./scripts/import_wenxuecity_cron.sh
  ./scripts/import_wenxuecity_cron.sh --limit 20
  ./scripts/import_wenxuecity_cron.sh --local --project vparkbb-local --dry-run
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --local)
      MODE="local"
      PROJECT_NAME="vparkbb-local"
      COMPOSE_FILES=("docker-compose.yml" "docker-compose.local.yml")
      shift
      ;;
    --project)
      PROJECT_NAME="$2"
      shift 2
      ;;
    --limit)
      LIMIT="$2"
      shift 2
      ;;
    --forum)
      FORUM="$2"
      shift 2
      ;;
    --forum-id)
      FORUM_ID="$2"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[wenxuecity-import] unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -f ".env" ]]; then
  set -a
  source ".env"
  set +a
fi

compose_cmd=(docker compose)
for f in "${COMPOSE_FILES[@]}"; do
  compose_cmd+=(-f "$f")
done

export COMPOSE_PROJECT_NAME="${PROJECT_NAME}"

cmd=(php ./scripts/import_wenxuecity.php --limit "${LIMIT}")
if [[ -n "${FORUM_ID}" && -n "${FORUM}" ]]; then
  echo "[wenxuecity-import] use either --forum-id or --forum, not both" >&2
  exit 1
fi
if [[ -n "${FORUM_ID}" ]]; then
  cmd+=(--forum-id "${FORUM_ID}")
elif [[ -n "${FORUM}" ]]; then
  cmd+=(--forum "${FORUM}")
fi
if [[ "${DRY_RUN}" -eq 1 ]]; then
  cmd+=(--dry-run)
fi

echo "[wenxuecity-import] mode=${MODE} project=${PROJECT_NAME}"
"${compose_cmd[@]}" exec -T php "${cmd[@]}"

if [[ "${DRY_RUN}" -eq 0 ]]; then
  echo "[wenxuecity-import] purging phpBB cache"
  "${compose_cmd[@]}" exec -T php php ./bin/phpbbcli.php cache:purge
fi

echo "[wenxuecity-import] done"
