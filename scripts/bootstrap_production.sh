#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

RUN_CERTBOT="true"
RUN_INSTALL="true"
FORCE_REINSTALL="false"

PROJECT_NAME=""
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
Usage: ./scripts/bootstrap_production.sh [options]

One-command Ubuntu production bootstrap for phpBB at /opt/vparkbb.

Options:
  --project <name>         Compose project name (default from .env COMPOSE_PROJECT_NAME or vparkbb)
  --admin-pass <password>  phpBB admin password (required if install step runs)
  --admin-email <email>    phpBB admin email (default: admin@victoriapark.io)
  --board-name <name>      phpBB board name (default: VictoriaPark.io)
  --board-lang <lang>      phpBB board language (default: zh_cmn_hans)
  --board-description <d>  phpBB board description
  --skip-install           Skip non-interactive phpBB install step
  --skip-certbot           Skip Let's Encrypt issuance step
  --force-reinstall        Pass through to install_phpbb.sh --force-reinstall
  -h, --help               Show this help

Examples:
  ./scripts/bootstrap_production.sh --admin-pass 'StrongPass123!'
  ./scripts/bootstrap_production.sh --skip-install --skip-certbot
USAGE
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--project)
			PROJECT_NAME="$2"
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
		--skip-certbot)
			RUN_CERTBOT="false"
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
			echo "[bootstrap] unknown option: $1" >&2
			usage
			exit 1
			;;
	esac
done

require_cmd() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "[bootstrap] missing required command: $1" >&2
		exit 1
	fi
}

require_cmd docker
require_cmd awk
require_cmd sed

sql_escape() {
	printf '%s' "$1" | sed "s/'/''/g"
}

if [[ ! -f ".env" ]]; then
	echo "[bootstrap] .env not found in ${PROJECT_ROOT}. Copy .env.example first." >&2
	exit 1
fi

set -a
source ".env"
set +a

PROJECT_NAME="${PROJECT_NAME:-${COMPOSE_PROJECT_NAME:-vparkbb}}"
export COMPOSE_PROJECT_NAME="${PROJECT_NAME}"

required_vars=(DB_NAME DB_USER DB_PASSWORD DB_ROOT_PASSWORD APP_DOMAIN APP_DOMAIN_ALT LETSENCRYPT_EMAIL)
for v in "${required_vars[@]}"; do
	if [[ -z "${!v:-}" ]]; then
		echo "[bootstrap] missing required .env variable: ${v}" >&2
		exit 1
	fi
done

TABLE_PREFIX="${PHPBB_TABLE_PREFIX:-}"
if [[ -z "${TABLE_PREFIX}" && -f "config.php" ]]; then
	TABLE_PREFIX="$(awk -F"'" '/^\$table_prefix =/ { print $2; exit }' config.php || true)"
fi
TABLE_PREFIX="${TABLE_PREFIX:-phpbb_}"

if [[ ! "${TABLE_PREFIX}" =~ ^[A-Za-z0-9_]+$ ]]; then
	echo "[bootstrap] unsafe table prefix detected: ${TABLE_PREFIX}" >&2
	exit 1
fi

compose_cmd=(docker compose -f docker-compose.yml)

echo "[bootstrap] project=${PROJECT_NAME}"
echo "[bootstrap] app_domain=${APP_DOMAIN} app_domain_alt=${APP_DOMAIN_ALT}"
echo "[bootstrap] preparing directories"
mkdir -p infra/certbot/www infra/certbot/conf backups/db backups/media
chmod +x scripts/backup.sh scripts/restore.sh scripts/install_phpbb.sh scripts/configure_forums.sh || true

echo "[bootstrap] building php image"
"${compose_cmd[@]}" build php

echo "[bootstrap] starting db + php"
"${compose_cmd[@]}" up -d db php

echo "[bootstrap] creating temporary TLS certificate for nginx bootstrap"
docker run --rm -v "$(pwd)/infra/certbot/conf:/etc/letsencrypt" alpine:3.20 sh -lc \
	"apk add --no-cache openssl >/dev/null && \
	 mkdir -p /etc/letsencrypt/live/${APP_DOMAIN} && \
	 openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
	   -keyout /etc/letsencrypt/live/${APP_DOMAIN}/privkey.pem \
	   -out /etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem \
	   -subj '/CN=${APP_DOMAIN}' && \
	 cp /etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem /etc/letsencrypt/live/${APP_DOMAIN}/chain.pem"

echo "[bootstrap] starting nginx"
"${compose_cmd[@]}" up -d nginx

if [[ "${RUN_CERTBOT}" == "true" ]]; then
	echo "[bootstrap] requesting/renewing Let's Encrypt certificate"
	"${compose_cmd[@]}" run --rm certbot certonly \
		--webroot -w /var/www/certbot \
		-d "${APP_DOMAIN}" -d "${APP_DOMAIN_ALT}" \
		--email "${LETSENCRYPT_EMAIL}" \
		--agree-tos --no-eff-email --non-interactive --keep-until-expiring
	"${compose_cmd[@]}" exec -T nginx nginx -s reload
else
	echo "[bootstrap] --skip-certbot enabled; keeping temporary cert"
fi

if [[ "${RUN_INSTALL}" == "true" ]]; then
	if [[ -s "config.php" && ! -d "install" && "${FORCE_REINSTALL}" != "true" ]]; then
		echo "[bootstrap] phpBB already appears installed (config.php present, install/ absent). Skipping install."
	else
		if [[ -z "${ADMIN_PASS}" ]]; then
			echo "[bootstrap] missing --admin-pass (required when install step runs)." >&2
			exit 1
		fi

		echo "[bootstrap] running non-interactive phpBB install"
		install_args=(
			--project "${PROJECT_NAME}"
			--admin-pass "${ADMIN_PASS}"
			--admin-email "${ADMIN_EMAIL}"
			--board-name "${BOARD_NAME}"
			--board-lang "${BOARD_LANG}"
			--board-description "${BOARD_DESCRIPTION}"
			--server-name "${APP_DOMAIN}"
			--server-port "${NGINX_HTTPS_PORT:-443}"
			--server-protocol "https://"
		)
		if [[ "${FORCE_REINSTALL}" == "true" ]]; then
			install_args+=(--force-reinstall)
		fi
		./scripts/install_phpbb.sh "${install_args[@]}"
	fi
else
	echo "[bootstrap] --skip-install enabled"
fi

installed_config_table="$("${compose_cmd[@]}" exec -T db mariadb -N -uroot -p"${DB_ROOT_PASSWORD}" -e \
	"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='${TABLE_PREFIX}config';")"

if [[ "${installed_config_table}" == "1" ]]; then
	echo "[bootstrap] enabling vpark/glue extension (idempotent)"
	ext_active="$("${compose_cmd[@]}" exec -T db mariadb -N -uroot -p"${DB_ROOT_PASSWORD}" -e \
		"USE ${DB_NAME}; SELECT COALESCE((SELECT ext_active FROM \`${TABLE_PREFIX}ext\` WHERE ext_name='vpark/glue' LIMIT 1), 0);")"
	if [[ "${ext_active}" == "1" ]]; then
		echo "[bootstrap] extension vpark/glue already enabled"
	else
		"${compose_cmd[@]}" exec -T php php ./bin/phpbbcli.php extension:enable vpark/glue
	fi

	DEFAULT_LANG_SQL="$(sql_escape "${DEFAULT_LANG}")"
	SITENAME_SQL="$(sql_escape "${SITENAME}")"
	SITE_DESC_SQL="$(sql_escape "${SITE_DESC}")"

	echo "[bootstrap] applying language + branding defaults"
	"${compose_cmd[@]}" exec -T db mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e \
		"USE ${DB_NAME}; \
		 INSERT INTO \`${TABLE_PREFIX}lang\` (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
		 SELECT 'en_us', 'en_us', 'English (American)', 'English (US)', 'phpBB Limited' \
		 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM \`${TABLE_PREFIX}lang\` WHERE lang_iso='en_us'); \
		 INSERT INTO \`${TABLE_PREFIX}lang\` (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
		 SELECT 'zh_cmn_hant', 'zh_cmn_hant', 'Mandarin Chinese (Traditional script)', '繁體中文', '竹貓星球' \
		 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM \`${TABLE_PREFIX}lang\` WHERE lang_iso='zh_cmn_hant'); \
		 INSERT INTO \`${TABLE_PREFIX}lang\` (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
		 SELECT 'fr', 'fr', 'French', 'Francais', 'phpBB-fr.com Team' \
		 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM \`${TABLE_PREFIX}lang\` WHERE lang_iso='fr'); \
		 INSERT INTO \`${TABLE_PREFIX}lang\` (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
		 SELECT 'es_x_tu', 'es_x_tu', 'Spanish (Casual Honorifics)', 'Espanol (Tu)', 'phpBB-es.com Team' \
		 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM \`${TABLE_PREFIX}lang\` WHERE lang_iso='es_x_tu'); \
		 UPDATE \`${TABLE_PREFIX}config\` SET config_value='${DEFAULT_LANG_SQL}' WHERE config_name='default_lang'; \
		 UPDATE \`${TABLE_PREFIX}config\` SET config_value='${SITENAME_SQL}' WHERE config_name='sitename'; \
		 UPDATE \`${TABLE_PREFIX}config\` SET config_value='${SITE_DESC_SQL}' WHERE config_name='site_desc'; \
		 UPDATE \`${TABLE_PREFIX}users\` SET user_lang='${DEFAULT_LANG_SQL}' WHERE user_id=1; \
		 UPDATE \`${TABLE_PREFIX}topics\` SET topic_title='Welcome to VictoriaPark.io' WHERE topic_id=1 AND topic_title LIKE '%phpBB%'; \
		 UPDATE \`${TABLE_PREFIX}posts\` SET post_subject='Welcome to VictoriaPark.io', post_text=REPLACE(REPLACE(post_text, 'phpBB3', 'VictoriaPark.io'), 'phpBB', 'VictoriaPark.io') WHERE post_id=1; \
		 UPDATE \`${TABLE_PREFIX}forums\` SET forum_last_post_subject='Welcome to VictoriaPark.io' WHERE forum_last_post_subject LIKE '%phpBB%';"

	echo "[bootstrap] purging phpBB cache"
	"${compose_cmd[@]}" exec -T php php ./bin/phpbbcli.php cache:purge
else
	echo "[bootstrap] phpBB schema not found yet (table ${TABLE_PREFIX}config missing); skipped extension/language/branding/cache steps."
fi

echo "[bootstrap] done"
echo
echo "Verification commands:"
echo "  docker compose -f docker-compose.yml ps"
echo "  curl -I https://${APP_DOMAIN}/healthz"
echo "  curl -sS https://${APP_DOMAIN}/ext/vpark/session_validate"
echo
echo "Next recommended step:"
echo "  ./scripts/configure_forums.sh --project ${PROJECT_NAME}"
