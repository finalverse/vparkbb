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

MODE="production"
PROJECT_NAME="${COMPOSE_PROJECT_NAME:-vparkbb}"
COMPOSE_FILES=("docker-compose.yml")

usage() {
  cat <<'USAGE'
Usage: ./scripts/configure_forums.sh [options]

Options:
  --local           Use docker-compose.local.yml and localhost defaults
  --project <name>  Compose project name override
  -h, --help        Show this help

Examples:
  ./scripts/configure_forums.sh
  ./scripts/configure_forums.sh --local --project vparkbb-local
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
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[forums] unknown option: $1" >&2
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

echo "[forums] mode=${MODE} project=${PROJECT_NAME}"
echo "[forums] applying forum taxonomy, ACL roles, and anti-spam defaults"
"${compose_cmd[@]}" exec -T php php ./scripts/phpbb_seed_forums.php

echo "[forums] purging cache"
"${compose_cmd[@]}" exec -T php php ./bin/phpbbcli.php cache:purge

echo "[forums] done"
