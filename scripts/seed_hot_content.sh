#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

MODE="production"
PROJECT_NAME="${COMPOSE_PROJECT_NAME:-vparkbb}"
COMPOSE_FILES=("docker-compose.yml")
TOPICS_PER_FORUM=8
FEED_LIMIT=40
SEED_PASSWORD="VictoriaPark!2026"
DRY_RUN=0

usage() {
  cat <<'USAGE'
Usage: ./scripts/seed_hot_content.sh [options]

Options:
  --local                 Use docker-compose.local.yml defaults
  --project <name>        Compose project name override
  --topics-per-forum <n>  Topics to create per forum (default: 8)
  --feed-limit <n>        Max RSS items pulled per forum query (default: 40)
  --password <value>      Password for generated AI users
  --dry-run               Show actions without creating posts
  -h, --help              Show help

Examples:
  ./scripts/seed_hot_content.sh
  ./scripts/seed_hot_content.sh --local --project vparkbb-local
  ./scripts/seed_hot_content.sh --topics-per-forum 8 --feed-limit 48
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
    --topics-per-forum)
      TOPICS_PER_FORUM="$2"
      shift 2
      ;;
    --feed-limit)
      FEED_LIMIT="$2"
      shift 2
      ;;
    --password)
      SEED_PASSWORD="$2"
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
      echo "[hot-seed] unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

compose_cmd=(docker compose)
for f in "${COMPOSE_FILES[@]}"; do
  compose_cmd+=(-f "$f")
done

export COMPOSE_PROJECT_NAME="${PROJECT_NAME}"

cmd=(php ./scripts/phpbb_seed_hot_content.php
  --topics-per-forum "${TOPICS_PER_FORUM}"
  --feed-limit "${FEED_LIMIT}"
  --password "${SEED_PASSWORD}")

if [[ "${DRY_RUN}" -eq 1 ]]; then
  cmd+=(--dry-run)
fi

echo "[hot-seed] mode=${MODE} project=${PROJECT_NAME}"
"${compose_cmd[@]}" exec -T php "${cmd[@]}"

if [[ "${DRY_RUN}" -eq 0 ]]; then
  echo "[hot-seed] purging phpBB cache"
  "${compose_cmd[@]}" exec -T php php ./bin/phpbbcli.php cache:purge
fi

echo "[hot-seed] done"

