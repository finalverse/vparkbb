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

ADMIN_NAME="${PHPBB_ADMIN_NAME:-admin}"
ADMIN_PASSWORD="${PHPBB_ADMIN_PASSWORD:-}"
ADMIN_EMAIL="${PHPBB_ADMIN_EMAIL:-admin@victoriapark.io}"

BOARD_LANG="${PHPBB_BOARD_LANG:-zh_cmn_hans}"
BOARD_NAME="${PHPBB_BOARD_NAME:-VictoriaPark.io}"
BOARD_DESCRIPTION="${PHPBB_BOARD_DESCRIPTION:-VictoriaPark.io forum}"

DBMS="${PHPBB_DBMS:-mysqli}"
DBHOST="${PHPBB_DBHOST:-db}"
DBPORT="${PHPBB_DBPORT:-3306}"
DBUSER="${PHPBB_DBUSER:-${DB_USER:-}}"
DBPASS="${PHPBB_DBPASS:-${DB_PASSWORD:-}}"
DBNAME="${PHPBB_DBNAME:-${DB_NAME:-}}"
TABLE_PREFIX="${PHPBB_TABLE_PREFIX:-phpbb_}"

SERVER_PROTOCOL="${PHPBB_SERVER_PROTOCOL:-https://}"
SERVER_NAME="${PHPBB_SERVER_NAME:-${APP_DOMAIN:-www.victoriapark.io}}"
SERVER_PORT="${PHPBB_SERVER_PORT:-${NGINX_HTTPS_PORT:-443}}"
SCRIPT_PATH="${PHPBB_SCRIPT_PATH:-/}"
COOKIE_SECURE="${PHPBB_COOKIE_SECURE:-true}"
FORCE_SERVER_VARS="${PHPBB_FORCE_SERVER_VARS:-true}"

DISABLE_INSTALL_DIR="true"
FORCE_REINSTALL="false"

usage() {
  cat <<'USAGE'
Usage: ./scripts/install_phpbb.sh [options]

Options:
  --local                     Use local compose override defaults
  --project <name>            Compose project name
  --admin-name <name>         phpBB admin username
  --admin-pass <password>     phpBB admin password (required)
  --admin-email <email>       phpBB admin email
  --board-name <name>         Forum name (default: VictoriaPark.io)
  --board-lang <lang>         Forum language code (default: zh_cmn_hans)
  --board-description <text>  Forum description
  --server-name <host>        Server name for phpBB
  --server-port <port>        Server port for phpBB
  --server-protocol <proto>   http:// or https://
  --script-path <path>        Script path (default: /)
  --cookie-secure <bool>      true/false
  --force-server-vars <bool>  true/false
  --dbms <name>               Database driver (default: mysqli)
  --dbhost <host>             DB host (default: db)
  --dbport <port>             DB port (default: 3306)
  --dbuser <user>             DB user (default from .env DB_USER)
  --dbpass <password>         DB password (default from .env DB_PASSWORD)
  --dbname <name>             DB name (default from .env DB_NAME)
  --table-prefix <prefix>     DB table prefix (default: phpbb_)
  --no-disable-install-dir    Keep install/ after completion
  --force-reinstall           Ignore non-empty config.php guard
  -h, --help                  Show this help

Examples:
  PHPBB_ADMIN_PASSWORD='StrongPass123!' ./scripts/install_phpbb.sh
  ./scripts/install_phpbb.sh --local --project vparkbb-local --admin-pass 'StrongPass123!'
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --local)
      MODE="local"
      COMPOSE_FILES=("docker-compose.yml" "docker-compose.local.yml")
      PROJECT_NAME="vparkbb-local"
      SERVER_PROTOCOL="http://"
      SERVER_NAME="localhost"
      SERVER_PORT="${NGINX_HTTP_PORT:-8080}"
      COOKIE_SECURE="false"
      FORCE_SERVER_VARS="true"
      shift
      ;;
    --project)
      PROJECT_NAME="$2"
      shift 2
      ;;
    --admin-name)
      ADMIN_NAME="$2"
      shift 2
      ;;
    --admin-pass)
      ADMIN_PASSWORD="$2"
      shift 2
      ;;
    --admin-email)
      ADMIN_EMAIL="$2"
      shift 2
      ;;
    --board-name)
      BOARD_NAME="$2"
      shift 2
      ;;
    --board-lang)
      BOARD_LANG="$2"
      shift 2
      ;;
    --board-description)
      BOARD_DESCRIPTION="$2"
      shift 2
      ;;
    --server-name)
      SERVER_NAME="$2"
      shift 2
      ;;
    --server-port)
      SERVER_PORT="$2"
      shift 2
      ;;
    --server-protocol)
      SERVER_PROTOCOL="$2"
      shift 2
      ;;
    --script-path)
      SCRIPT_PATH="$2"
      shift 2
      ;;
    --cookie-secure)
      COOKIE_SECURE="$2"
      shift 2
      ;;
    --force-server-vars)
      FORCE_SERVER_VARS="$2"
      shift 2
      ;;
    --dbms)
      DBMS="$2"
      shift 2
      ;;
    --dbhost)
      DBHOST="$2"
      shift 2
      ;;
    --dbport)
      DBPORT="$2"
      shift 2
      ;;
    --dbuser)
      DBUSER="$2"
      shift 2
      ;;
    --dbpass)
      DBPASS="$2"
      shift 2
      ;;
    --dbname)
      DBNAME="$2"
      shift 2
      ;;
    --table-prefix)
      TABLE_PREFIX="$2"
      shift 2
      ;;
    --no-disable-install-dir)
      DISABLE_INSTALL_DIR="false"
      shift
      ;;
    --force-reinstall)
      FORCE_REINSTALL="true"
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[install] unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "${ADMIN_PASSWORD}" ]]; then
  echo "[install] missing admin password. Set PHPBB_ADMIN_PASSWORD or pass --admin-pass." >&2
  exit 1
fi

if [[ -z "${DBUSER}" || -z "${DBPASS}" || -z "${DBNAME}" ]]; then
  echo "[install] missing DB settings. Ensure DB_USER/DB_PASSWORD/DB_NAME exist in .env or pass --dbuser/--dbpass/--dbname." >&2
  exit 1
fi

if [[ "${FORCE_REINSTALL}" != "true" && -s "config.php" ]]; then
  echo "[install] config.php is not empty, board appears already installed. Use --force-reinstall if intentional." >&2
  exit 1
fi

if [[ ! -d "install" ]]; then
  echo "[install] install/ directory not found. phpBB installer is unavailable." >&2
  exit 1
fi

yaml_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

INSTALL_CONFIG=".tmp.install-config.$(date +%Y%m%d_%H%M%S).yml"
CONFIG_BACKUP=""
cleanup() {
  local status=$?
  rm -f "${INSTALL_CONFIG}"
  if [[ -n "${CONFIG_BACKUP}" && -f "${CONFIG_BACKUP}" ]]; then
    if [[ "${status}" -ne 0 ]]; then
      mv "${CONFIG_BACKUP}" config.php
      echo "[install] restored original config.php after failed install"
    else
      rm -f "${CONFIG_BACKUP}"
    fi
  fi
  exit "${status}"
}
trap cleanup EXIT

if [[ "${FORCE_REINSTALL}" == "true" && -f "config.php" ]]; then
  CONFIG_BACKUP=".tmp.config.php.backup.$(date +%Y%m%d_%H%M%S)"
  cp config.php "${CONFIG_BACKUP}"
  : > config.php
  echo "[install] prepared empty config.php for forced reinstall"
fi

cat > "${INSTALL_CONFIG}" <<YAML
installer:
  admin:
    name: "$(yaml_escape "${ADMIN_NAME}")"
    password: "$(yaml_escape "${ADMIN_PASSWORD}")"
    email: "$(yaml_escape "${ADMIN_EMAIL}")"
  board:
    lang: "$(yaml_escape "${BOARD_LANG}")"
    name: "$(yaml_escape "${BOARD_NAME}")"
    description: "$(yaml_escape "${BOARD_DESCRIPTION}")"
  database:
    dbms: "$(yaml_escape "${DBMS}")"
    dbhost: "$(yaml_escape "${DBHOST}")"
    dbport: "$(yaml_escape "${DBPORT}")"
    dbuser: "$(yaml_escape "${DBUSER}")"
    dbpasswd: "$(yaml_escape "${DBPASS}")"
    dbname: "$(yaml_escape "${DBNAME}")"
    table_prefix: "$(yaml_escape "${TABLE_PREFIX}")"
  email:
    enabled: false
  server:
    cookie_secure: ${COOKIE_SECURE}
    server_protocol: "$(yaml_escape "${SERVER_PROTOCOL}")"
    force_server_vars: ${FORCE_SERVER_VARS}
    server_name: "$(yaml_escape "${SERVER_NAME}")"
    server_port: ${SERVER_PORT}
    script_path: "$(yaml_escape "${SCRIPT_PATH}")"
  extensions: []
YAML

compose_cmd=(docker compose)
for f in "${COMPOSE_FILES[@]}"; do
  compose_cmd+=(-f "$f")
done

export COMPOSE_PROJECT_NAME="${PROJECT_NAME}"

echo "[install] mode=${MODE} project=${PROJECT_NAME}"
echo "[install] validating installer config ${INSTALL_CONFIG}"
"${compose_cmd[@]}" exec -T php php ./install/phpbbcli.php install:config:validate "${INSTALL_CONFIG}"

echo "[install] running non-interactive installer"
"${compose_cmd[@]}" exec -T php php ./install/phpbbcli.php install --no-interaction "${INSTALL_CONFIG}"

if [[ "${DISABLE_INSTALL_DIR}" == "true" && -d "install" ]]; then
  mv install install.disabled
  echo "[install] installer directory disabled: install -> install.disabled"
fi

echo "[install] phpBB installation completed successfully"
