#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

PROJECT_NAME="vparkbb-local"
HTTP_PORT="8080"
HTTPS_PORT="8443"
RUN_INSTALL="true"
FORCE_REINSTALL="false"

ADMIN_PASS="${PHPBB_ADMIN_PASSWORD:-}"
ADMIN_EMAIL="${PHPBB_ADMIN_EMAIL:-admin@victoriapark.io}"
BOARD_NAME="${PHPBB_BOARD_NAME:-VictoriaPark.io}"
BOARD_LANG="${PHPBB_BOARD_LANG:-zh_cmn_hans}"
BOARD_DESCRIPTION="${PHPBB_BOARD_DESCRIPTION:-VictoriaPark.io forum}"

SITENAME="${PHPBB_SITE_NAME:-VictoriaPark.io}"
SITE_DESC="${PHPBB_SITE_DESC:-VictoriaPark.io}"
DEFAULT_LANG="${PHPBB_DEFAULT_LANG:-zh_cmn_hans}"

usage() {
	cat <<'USAGE'
Usage: ./scripts/bootstrap_local.sh [options]

One-command local bootstrap for phpBB dev stack (vparkbb-local).

Options:
  --project <name>         Compose project name (default: vparkbb-local)
  --http-port <port>       Local HTTP port (default: 8080)
  --https-port <port>      Local HTTPS port (default: 8443)
  --admin-pass <password>  phpBB admin password (required if install runs)
  --admin-email <email>    phpBB admin email (default: admin@victoriapark.io)
  --board-name <name>      phpBB board name (default: VictoriaPark.io)
  --board-lang <lang>      phpBB board language (default: zh_cmn_hans)
  --board-description <d>  phpBB board description
  --skip-install           Skip install step (only valid if DB already has phpBB tables)
  --force-reinstall        Force reinstall when phpBB tables already exist
  -h, --help               Show this help

Examples:
  ./scripts/bootstrap_local.sh --admin-pass 'StrongPass123!'
  ./scripts/bootstrap_local.sh --skip-install
USAGE
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--project)
			PROJECT_NAME="$2"
			shift 2
			;;
		--http-port)
			HTTP_PORT="$2"
			shift 2
			;;
		--https-port)
			HTTPS_PORT="$2"
			shift 2
			;;
		--admin-pass)
			ADMIN_PASS="$2"
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
		--skip-install)
			RUN_INSTALL="false"
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
			echo "[local-bootstrap] unknown option: $1" >&2
			usage
			exit 1
			;;
	esac
done

require_cmd() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "[local-bootstrap] missing required command: $1" >&2
		exit 1
	fi
}

sql_escape() {
	printf '%s' "$1" | sed "s/'/''/g"
}

require_cmd docker
require_cmd awk
require_cmd sed

if [[ ! -f ".env" ]]; then
	echo "[local-bootstrap] .env not found in ${PROJECT_ROOT}. Copy .env.example first." >&2
	exit 1
fi

set -a
source ".env"
set +a

required_vars=(DB_NAME DB_USER DB_PASSWORD DB_ROOT_PASSWORD)
for v in "${required_vars[@]}"; do
	if [[ -z "${!v:-}" ]]; then
		echo "[local-bootstrap] missing required .env variable: ${v}" >&2
		exit 1
	fi
done

TABLE_PREFIX="${PHPBB_TABLE_PREFIX:-}"
if [[ -z "${TABLE_PREFIX}" && -f "config.php" ]]; then
	TABLE_PREFIX="$(awk -F"'" '/^\$table_prefix =/ { print $2; exit }' config.php || true)"
fi
TABLE_PREFIX="${TABLE_PREFIX:-phpbb_}"

if [[ ! "${TABLE_PREFIX}" =~ ^[A-Za-z0-9_]+$ ]]; then
	echo "[local-bootstrap] unsafe table prefix detected: ${TABLE_PREFIX}" >&2
	exit 1
fi

export COMPOSE_PROJECT_NAME="${PROJECT_NAME}"
export NGINX_HTTP_PORT="${HTTP_PORT}"
export NGINX_HTTPS_PORT="${HTTPS_PORT}"

compose_cmd=(docker compose -f docker-compose.yml -f docker-compose.local.yml)

echo "[local-bootstrap] project=${PROJECT_NAME} http=${HTTP_PORT} https=${HTTPS_PORT}"
echo "[local-bootstrap] starting local stack"
"${compose_cmd[@]}" up -d --build db php nginx

table_exists="$("${compose_cmd[@]}" exec -T db mariadb -N -uroot -p"${DB_ROOT_PASSWORD}" -e \
	"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='${TABLE_PREFIX}config';")"

if [[ "${table_exists}" == "1" && "${FORCE_REINSTALL}" != "true" ]]; then
	echo "[local-bootstrap] phpBB tables already exist; skipping install."
elif [[ "${RUN_INSTALL}" != "true" ]]; then
	echo "[local-bootstrap] --skip-install requested but phpBB tables are missing. Cannot continue." >&2
	echo "[local-bootstrap] rerun with --admin-pass to install phpBB first." >&2
	exit 1
else
	if [[ -z "${ADMIN_PASS}" ]]; then
		echo "[local-bootstrap] missing --admin-pass (required for first local install)." >&2
		exit 1
	fi

	if [[ ! -d "install" && -d "install.disabled" ]]; then
		echo "[local-bootstrap] enabling installer directory (install.disabled -> install)"
		mv install.disabled install
	fi

	if [[ ! -d "install" ]]; then
		echo "[local-bootstrap] install/ directory not found; cannot run phpBB installer." >&2
		exit 1
	fi

	echo "[local-bootstrap] running phpBB non-interactive install"
	install_args=(
		--local
		--project "${PROJECT_NAME}"
		--admin-pass "${ADMIN_PASS}"
		--admin-email "${ADMIN_EMAIL}"
		--board-name "${BOARD_NAME}"
		--board-lang "${BOARD_LANG}"
		--board-description "${BOARD_DESCRIPTION}"
	)
	if [[ -s "config.php" || "${FORCE_REINSTALL}" == "true" ]]; then
		install_args+=(--force-reinstall)
	fi
	./scripts/install_phpbb.sh "${install_args[@]}"
fi

table_exists="$("${compose_cmd[@]}" exec -T db mariadb -N -uroot -p"${DB_ROOT_PASSWORD}" -e \
	"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='${TABLE_PREFIX}config';")"
if [[ "${table_exists}" != "1" ]]; then
	echo "[local-bootstrap] phpBB install did not complete (missing ${TABLE_PREFIX}config)." >&2
	exit 1
fi

echo "[local-bootstrap] enabling vpark/glue extension (idempotent)"
ext_active="$("${compose_cmd[@]}" exec -T db mariadb -N -uroot -p"${DB_ROOT_PASSWORD}" -e \
	"USE ${DB_NAME}; SELECT COALESCE((SELECT ext_active FROM \`${TABLE_PREFIX}ext\` WHERE ext_name='vpark/glue' LIMIT 1), 0);")"
if [[ "${ext_active}" == "1" ]]; then
	echo "[local-bootstrap] extension vpark/glue already enabled"
else
	"${compose_cmd[@]}" exec -T php php ./bin/phpbbcli.php extension:enable vpark/glue
fi

DEFAULT_LANG_SQL="$(sql_escape "${DEFAULT_LANG}")"
SITENAME_SQL="$(sql_escape "${SITENAME}")"
SITE_DESC_SQL="$(sql_escape "${SITE_DESC}")"

echo "[local-bootstrap] applying language + branding defaults"
"${compose_cmd[@]}" exec -T db mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e \
	"USE ${DB_NAME}; \
	 INSERT INTO \`${TABLE_PREFIX}lang\` (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
	 SELECT 'zh_cmn_hant', 'zh_cmn_hant', 'Mandarin Chinese (Traditional script)', '繁體中文', '竹貓星球' \
	 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM \`${TABLE_PREFIX}lang\` WHERE lang_iso='zh_cmn_hant'); \
	 UPDATE \`${TABLE_PREFIX}config\` SET config_value='${DEFAULT_LANG_SQL}' WHERE config_name='default_lang'; \
	 UPDATE \`${TABLE_PREFIX}config\` SET config_value='${SITENAME_SQL}' WHERE config_name='sitename'; \
	 UPDATE \`${TABLE_PREFIX}config\` SET config_value='${SITE_DESC_SQL}' WHERE config_name='site_desc'; \
	 UPDATE \`${TABLE_PREFIX}users\` SET user_lang='${DEFAULT_LANG_SQL}' WHERE user_id=1; \
	 UPDATE \`${TABLE_PREFIX}topics\` SET topic_title='Welcome to VictoriaPark.io' WHERE topic_id=1 AND topic_title LIKE '%phpBB%'; \
	 UPDATE \`${TABLE_PREFIX}posts\` SET post_subject='Welcome to VictoriaPark.io', post_text=REPLACE(REPLACE(post_text, 'phpBB3', 'VictoriaPark.io'), 'phpBB', 'VictoriaPark.io') WHERE post_id=1; \
	 UPDATE \`${TABLE_PREFIX}forums\` SET forum_last_post_subject='Welcome to VictoriaPark.io' WHERE forum_last_post_subject LIKE '%phpBB%';"

echo "[local-bootstrap] purging phpBB cache"
"${compose_cmd[@]}" exec -T php php ./bin/phpbbcli.php cache:purge

echo "[local-bootstrap] done"
echo "  Forum URL: http://localhost:${HTTP_PORT}/"
echo "  Health:    http://localhost:${HTTP_PORT}/healthz"
echo "  SSO-lite:  http://localhost:${HTTP_PORT}/ext/vpark/session_validate"
