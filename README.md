# VictoriaPark phpBB (`vparkbb`) Deployment Guide

This repository now contains a production-oriented Docker stack for `www.victoriapark.io` (with apex `victoriapark.io` redirected to `www`):

- `php-fpm` (custom image with required phpBB extensions)
- `nginx` (TLS termination, security headers, phpBB routing)
- `mariadb`
- `certbot` (Let's Encrypt issuance/renewal helper)

## Environment Separation (Important)

Keep phpBB and Rust stacks separated in both local and production:

- Local phpBB: `~/VictoriaPark/vparkbb`
- Local Rust portal/AI: `~/VictoriaPark/vpark`
- Production phpBB (Ubuntu): `/opt/vparkbb`
- Production Rust portal/AI (Ubuntu): `/opt/vpark`

Rules:

- Do not run phpBB from the Rust repo.
- Do not mount local macOS paths on production Ubuntu.
- phpBB is the forum kernel (posts/threads/replies/moderation stay in phpBB).
- Rust stack is additive (portal/search/AI/guides/mod console).

## Files Added

- `docker-compose.yml`
- `docker-compose.local.yml`
- `nginx.conf`
- `nginx.local.conf`
- `docker/php-fpm/Dockerfile`
- `docker/php-fpm/php.ini`
- `docker/php-fpm/www-health.conf`
- `scripts/backup.sh`
- `scripts/restore.sh`
- `scripts/bootstrap_local.sh`
- `scripts/bootstrap_production.sh`
- `.env.example`

## 1) Prerequisites

- Docker Engine + Docker Compose plugin
- DNS A/AAAA records for `victoriapark.io` and `www.victoriapark.io` pointing to this host
- Ports `80` and `443` reachable from the internet

## 1A) Local vs Production Modes

Local dev mode (macOS):

- Path: `~/VictoriaPark/vparkbb`
- Use `docker-compose.local.yml`
- Ports: `8080/8443` recommended
- Project name: `vparkbb-local`
- No Let's Encrypt required

Production mode (Ubuntu server):

- Path: `/opt/vparkbb`
- Use `docker-compose.yml` only
- Ports: `80/443`
- Project name: `vparkbb`
- Real DNS + Let's Encrypt required

## 2) Initial Setup

```bash
cd ~/VictoriaPark/vparkbb
cp .env.example .env
mkdir -p infra/certbot/www infra/certbot/conf backups/db backups/media scripts
chmod +x scripts/*.sh
```

Edit `.env` and set strong passwords.

## 2A) Local Dev Bootstrap (Recommended)

Use this for local development on macOS. It is HTTP-only and uses `nginx.local.conf`.

```bash
cd ~/VictoriaPark/vparkbb
./scripts/bootstrap_local.sh --admin-pass '<strong_admin_password>'
```

What this command does:

- Starts local stack with `vparkbb-local` on `8080/8443`
- Installs phpBB when local DB is empty
- Enables `vpark/glue`
- Applies defaults:
  - `sitename=VictoriaPark.io`
  - `site_desc=VictoriaPark.io`
  - `default_lang=zh_cmn_hans`
  - `anonymous user (user_id=1) language=zh_cmn_hans`
  - ensures `zh_cmn_hant` exists
- Purges phpBB cache

Local checks:

```bash
curl -fsS http://localhost:8080/healthz
curl -sS http://localhost:8080/ext/vpark/session_validate
COMPOSE_PROJECT_NAME=vparkbb-local docker compose -f docker-compose.yml -f docker-compose.local.yml ps
```

Local URLs:

- Forum: `http://localhost:8080/`
- Health: `http://localhost:8080/healthz`
- SSO-lite validate: `http://localhost:8080/ext/vpark/session_validate`

Stop local stack:

```bash
COMPOSE_PROJECT_NAME=vparkbb-local docker compose -f docker-compose.yml -f docker-compose.local.yml down --remove-orphans
```

Common local error:

- `SQL ERROR [ mysqli ] Table 'phpbb.phpbb_config' doesn't exist [1146]`
- Cause: local DB volume is empty; phpBB has not been installed yet.
- Fix: run `./scripts/bootstrap_local.sh --admin-pass '<strong_admin_password>'`

Manual local flow (without bootstrap script):

```bash
cd ~/VictoriaPark/vparkbb
COMPOSE_PROJECT_NAME=vparkbb-local NGINX_HTTP_PORT=8080 NGINX_HTTPS_PORT=8443 \
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build db php nginx

# if installer dir is disabled, enable it first
if [ -d install.disabled ] && [ ! -d install ]; then mv install.disabled install; fi

./scripts/install_phpbb.sh --local --project vparkbb-local --admin-pass '<strong_admin_password>' --force-reinstall
```

## 2B) Local dev command going forward
```bash
cd ~/VictoriaPark/vparkbb
./scripts/bootstrap_local.sh --admin-pass '<your_local_admin_password>'
```
If already installed and you only want sync/apply defaults:
```bash
cd ~/VictoriaPark/vparkbb
./scripts/bootstrap_local.sh --skip-install
```
Note: I ran bootstrap with LocalDev123! in your current local DB to validate end-to-end.

Optional next steps
Change local admin password in ACP (or rerun local with your preferred password after resetting local volume).
If you want a fresh local reset:
```bash
cd ~/VictoriaPark/vparkbb
COMPOSE_PROJECT_NAME=vparkbb-local docker compose -f docker-compose.yml -f docker-compose.local.yml down -v --remove-orphans
./scripts/bootstrap_local.sh --admin-pass '<new_password>'
```

## 2C) Target Production Server Checklist

`www.victoriapark.io` as CNAME is fine, but apex `victoriapark.io` must still resolve directly (A/AAAA, ALIAS/ANAME, or equivalent from your DNS provider).

On the target server:

```bash
cd /opt/vparkbb
cp .env.example .env
mkdir -p infra/certbot/www infra/certbot/conf backups/db backups/media
```

Edit `.env`:

- `APP_DOMAIN=www.victoriapark.io`
- `APP_DOMAIN_ALT=victoriapark.io`
- `NGINX_HTTP_PORT=80`
- `NGINX_HTTPS_PORT=443`
- Strong `DB_PASSWORD` and `DB_ROOT_PASSWORD`

Bring up stack:

```bash
docker compose build php
docker compose up -d db php
```

Create temporary cert (so nginx can start first time), then start nginx:

```bash
set -a && source .env && set +a
docker run --rm -v "$(pwd)/infra/certbot/conf:/etc/letsencrypt" alpine:3.20 sh -lc \
  "apk add --no-cache openssl >/dev/null && \
   mkdir -p /etc/letsencrypt/live/${APP_DOMAIN} && \
   openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
     -keyout /etc/letsencrypt/live/${APP_DOMAIN}/privkey.pem \
     -out /etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem \
     -subj '/CN=${APP_DOMAIN}' && \
   cp /etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem /etc/letsencrypt/live/${APP_DOMAIN}/chain.pem"
docker compose up -d nginx
```

Issue real cert:

```bash
set -a && source .env && set +a
docker compose run --rm certbot certonly \
  --webroot -w /var/www/certbot \
  -d "${APP_DOMAIN}" -d "${APP_DOMAIN_ALT}" \
  --email "${LETSENCRYPT_EMAIL}" \
  --agree-tos --no-eff-email
docker compose exec nginx nginx -s reload
```

Then continue installer at:

- `https://www.victoriapark.io/install/app.php/install`
- Board name: `VictoriaPark.io`

## 2C) One-Command Ubuntu Bootstrap (Recommended)

On Ubuntu production server:

```bash
cd /opt/vparkbb
cp .env.example .env
# edit .env first: APP_DOMAIN, APP_DOMAIN_ALT, LETSENCRYPT_EMAIL, DB_* secrets
./scripts/bootstrap_production.sh --admin-pass '<strong_admin_password>'
```

What this command does:

- Builds php image and starts `db/php/nginx`
- Creates temporary TLS cert, then requests/renews Let's Encrypt cert
- Runs non-interactive phpBB install (when needed)
- Enables `vpark/glue`
- Sets defaults:
  - `sitename=VictoriaPark.io`
  - `site_desc=VictoriaPark.io`
  - `default_lang=zh_cmn_hans`
  - `anonymous user (user_id=1) language=zh_cmn_hans`
  - ensures `zh_cmn_hant` exists in language table
- Purges phpBB cache

Useful flags:

- `--skip-install` if board is already installed
- `--skip-certbot` if DNS is not ready yet
- `--project <name>` to override compose project name

## 3) Manual Production Bootstrap (Reference)

Build and start DB/PHP first:

```bash
docker compose build php
docker compose up -d db php
```

Create a 1-day temporary self-signed cert (needed so nginx can start before first Let's Encrypt issue):

```bash
set -a && source .env && set +a
docker run --rm -v "$(pwd)/infra/certbot/conf:/etc/letsencrypt" alpine:3.20 sh -lc \
  "apk add --no-cache openssl >/dev/null && \
   mkdir -p /etc/letsencrypt/live/${APP_DOMAIN} && \
   openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
     -keyout /etc/letsencrypt/live/${APP_DOMAIN}/privkey.pem \
     -out /etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem \
     -subj '/CN=${APP_DOMAIN}' && \
   cp /etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem /etc/letsencrypt/live/${APP_DOMAIN}/chain.pem"
```

Start nginx:

```bash
docker compose up -d nginx
```

## 3A) Automated phpBB Install (Reference)

You can install phpBB non-interactively with:

```bash
./scripts/install_phpbb.sh --admin-pass '<strong_admin_password>'
```

This script:

- builds installer YAML from your `.env` + flags
- runs `install/phpbbcli.php` in the `php` container
- sets board name default to `VictoriaPark.io`
- disables installer directory (`install -> install.disabled`) on success

For localhost dry-run:

```bash
COMPOSE_PROJECT_NAME=vparkbb-local NGINX_HTTP_PORT=8080 NGINX_HTTPS_PORT=8443 \
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build db php nginx

./scripts/install_phpbb.sh \
  --local \
  --project vparkbb-local \
  --admin-pass '<strong_admin_password>'
```

For production:

```bash
docker compose up -d db php
./scripts/install_phpbb.sh --admin-pass '<strong_admin_password>'
```

## 4) Issue Real Let's Encrypt Certificate

```bash
set -a && source .env && set +a
docker compose run --rm certbot certonly \
  --webroot -w /var/www/certbot \
  -d "${APP_DOMAIN}" -d "${APP_DOMAIN_ALT}" \
  --email "${LETSENCRYPT_EMAIL}" \
  --agree-tos --no-eff-email

docker compose exec nginx nginx -s reload
```

If issuance fails, verify both DNS records point to this host and that ports `80/443` are reachable from the internet.

Now open `https://www.victoriapark.io/install/app.php/install` and finish phpBB installation.

If you already used `./scripts/install_phpbb.sh`, skip web installer entirely.

In installer, set forum name (board name) to: `VictoriaPark.io`.

Use these DB fields in installer:

- DB type: `MySQL with MySQLi Extension`
- DB host: `db`
- DB port: `3306`
- DB name/user/password from `.env`

After install, disable installer:

```bash
mv install install.disabled
```

## 5) Healthchecks / Endpoints

- HTTP/HTTPS liveness: `GET /healthz` returns `ok`
- PHP-FPM ping: `GET /php-fpm-ping` (localhost-only from nginx container)

Checks:

```bash
curl -fsS http://127.0.0.1/healthz
docker compose exec nginx wget -q -O - http://127.0.0.1/php-fpm-ping
docker compose ps
```

## 6) Nightly Backups (DB + 14-day retention)

Manual run:

```bash
./scripts/backup.sh
```

Install nightly cron at 02:00:

```bash
(crontab -l 2>/dev/null; echo "0 2 * * * cd ~/VictoriaPark/vparkbb && ./scripts/backup.sh >> ./backups/backup.log 2>&1") | crontab -
```

## 7) Certificate Renewal (Nightly)

Install nightly renewal cron at 03:00:

```bash
(crontab -l 2>/dev/null; echo "0 3 * * * cd ~/VictoriaPark/vparkbb && docker compose run --rm certbot renew --webroot -w /var/www/certbot --quiet && docker compose exec nginx nginx -s reload") | crontab -
```

## 8) Volume/Media Backup Guidance (attachments/avatars)

phpBB user content is mainly in:

- `files/` (attachments)
- `images/avatars/upload/` (uploaded avatars)
- `store/` (export/import artifacts)

Backup media snapshot:

```bash
mkdir -p backups/media
tar -czf "backups/media/phpbb_media_$(date +%Y%m%d_%H%M%S).tar.gz" files images/avatars/upload store
```

Restore media snapshot:

```bash
tar -xzf backups/media/<media-backup-file>.tar.gz
```

## 9) Database Restore

Put forum in maintenance mode first (ACP), then:

```bash
./scripts/restore.sh backups/db/<backup-file>.sql.gz
```

## 10) Upgrade Procedure

1. Take DB and media backups:

```bash
./scripts/backup.sh
tar -czf "backups/media/pre_upgrade_$(date +%Y%m%d_%H%M%S).tar.gz" files images/avatars/upload store config.php ext
```

2. Update containers:

```bash
docker compose pull db nginx certbot
docker compose build --pull php
docker compose up -d
```

3. Apply phpBB update package and run DB updater at `https://www.victoriapark.io/install/app.php/update`.
4. Disable installer again: `mv install install.disabled` (if update re-creates it).

## 11) Secret Rotation

Rotate DB app password:

```bash
set -a && source .env && set +a
export NEW_DB_PASSWORD='replace_with_new_strong_password'
docker compose exec db mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e \
  "ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${NEW_DB_PASSWORD}'; FLUSH PRIVILEGES;"
```

Then update:

1. `.env` -> `DB_PASSWORD`
2. phpBB `config.php` -> `$dbpasswd`
3. Restart services:

```bash
docker compose up -d db php nginx
```

## 12) Minimal Monitoring Notes

Live logs:

```bash
docker compose logs -f --tail=200 nginx php db
```

Host/container disk checks:

```bash
docker system df
du -sh ~/VictoriaPark/vparkbb/backups ~/VictoriaPark/vparkbb/files ~/VictoriaPark/vparkbb/images/avatars/upload
```

DB connectivity check:

```bash
set -a && source .env && set +a
docker compose exec db mariadb -u"${DB_USER}" -p"${DB_PASSWORD}" -e "SELECT NOW() AS db_time;"
```

## 13) Chinese Language Pack Sync (Quick)

Use this quick sync, then follow Section 16 for default language SQL and cache purge:

```bash
rsync -a ~/VictoriaPark/mandarin_chinese_simplified_script/language/zh_cmn_hans/ ./language/zh_cmn_hans/
rsync -a ~/VictoriaPark/mandarin_chinese_traditional_script/language/zh_cmn_hant/ ./language/zh_cmn_hant/
```

## 14) Forum Taxonomy + Permissions Bootstrap

Use the automation script to seed VictoriaPark categories/boards, baseline roles, and anti-spam defaults:

```bash
chmod +x scripts/configure_forums.sh
```

Local:

```bash
./scripts/configure_forums.sh --local --project vparkbb-local
```

Production:

```bash
./scripts/configure_forums.sh --project vparkbb
```

This script will:

- create/update forum categories and boards for `VictoriaPark.io`
- ensure groups: `TRUSTED`, `BOARD_MODERATORS`
- apply group/forum permissions for guest, registered, trusted, moderator, and admin scopes
- set baseline registration and anti-spam controls

Detailed matrix and SOP: `docs/forum_governance.md`.

## 15) Portal Glue (phpBB-side, no core hacks)

This repo includes `ext/vpark/glue`:

- Header portal link (`门户`)
- Footer portal links (`门户 | 城市分区`)
- Topic page `Summary/摘要` button to `VPARK_PORTAL_URL/topic/{topic_id}`
- Session validation endpoint: `/ext/vpark/session_validate`

Enable and verify:

```bash
docker compose exec -T php php ./bin/phpbbcli.php extension:enable vpark/glue
docker compose exec -T php php ./bin/phpbbcli.php cache:purge
```

Endpoint check (guest response example):

```bash
curl -sS https://www.victoriapark.io/ext/vpark/session_validate
```

Expected shape:

```json
{"authenticated":false,"user_id":0,"username":"","user_lang":"zh_cmn_hans"}
```

## 16) Language Packs (Simplified default + user switch)

Installed language dirs:

- `language/en`
- `language/en_us`
- `language/fr`
- `language/es_x_tu`
- `language/zh_cmn_hans`
- `language/zh_cmn_hant`

Install/update from your local packs:

```bash
rsync -a ~/VictoriaPark/mandarin_chinese_simplified_script/language/zh_cmn_hans/ ./language/zh_cmn_hans/
rsync -a ~/VictoriaPark/mandarin_chinese_traditional_script/language/zh_cmn_hant/ ./language/zh_cmn_hant/
rsync -a ~/VictoriaPark/american_english_4_15_0/language/en_us/ ./language/en_us/
rsync -a ~/VictoriaPark/french_4_15_0/language/fr/ ./language/fr/
rsync -a ~/VictoriaPark/spanish_casual_honorifics_3_3_15/language/es_x_tu/ ./language/es_x_tu/

rsync -a ~/VictoriaPark/american_english_4_15_0/ext/phpbb/viglink/language/en_us/ ./ext/phpbb/viglink/language/en_us/
rsync -a ~/VictoriaPark/french_4_15_0/ext/phpbb/viglink/language/fr/ ./ext/phpbb/viglink/language/fr/
rsync -a ~/VictoriaPark/spanish_casual_honorifics_3_3_15/ext/phpbb/viglink/language/es_x_tu/ ./ext/phpbb/viglink/language/es_x_tu/

rsync -a ~/VictoriaPark/mandarin_chinese_traditional_script/ext/phpbb/viglink/language/zh_cmn_hant/ ./ext/phpbb/viglink/language/zh_cmn_hant/

rsync -a ~/VictoriaPark/american_english_4_15_0/styles/prosilver/theme/en_us/ ./styles/prosilver/theme/en_us/
rsync -a ~/VictoriaPark/french_4_15_0/styles/prosilver/theme/fr/ ./styles/prosilver/theme/fr/
rsync -a ~/VictoriaPark/spanish_casual_honorifics_3_3_15/styles/prosilver/theme/es_x_tu/ ./styles/prosilver/theme/es_x_tu/
rsync -a ~/VictoriaPark/mandarin_chinese_traditional_script/styles/prosilver/theme/zh_cmn_hant/ ./styles/prosilver/theme/zh_cmn_hant/

rsync -a ~/VictoriaPark/american_english_4_15_0/styles/prosilver/theme/en_us/ ./styles/victoriaclassic/theme/en_us/
rsync -a ~/VictoriaPark/french_4_15_0/styles/prosilver/theme/fr/ ./styles/victoriaclassic/theme/fr/
rsync -a ~/VictoriaPark/spanish_casual_honorifics_3_3_15/styles/prosilver/theme/es_x_tu/ ./styles/victoriaclassic/theme/es_x_tu/
rsync -a ~/VictoriaPark/mandarin_chinese_traditional_script/styles/prosilver/theme/zh_cmn_hant/ ./styles/victoriaclassic/theme/zh_cmn_hant/
```

Set default board language to Simplified Chinese:

```bash
set -a && source .env && set +a
docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e \
  "USE ${DB_NAME}; \
   INSERT INTO phpbb_lang (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
   SELECT 'en_us', 'en_us', 'English (American)', 'English (US)', 'phpBB Limited' \
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM phpbb_lang WHERE lang_iso='en_us'); \
   INSERT INTO phpbb_lang (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
   SELECT 'zh_cmn_hant', 'zh_cmn_hant', 'Mandarin Chinese (Traditional script)', '繁體中文', '竹貓星球' \
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM phpbb_lang WHERE lang_iso='zh_cmn_hant'); \
   INSERT INTO phpbb_lang (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
   SELECT 'fr', 'fr', 'French', 'Français', 'phpBB-fr.com Team' \
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM phpbb_lang WHERE lang_iso='fr'); \
   INSERT INTO phpbb_lang (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author) \
   SELECT 'es_x_tu', 'es_x_tu', 'Spanish (Casual Honorifics)', 'Español (Tú)', 'phpBB-es.com Team' \
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM phpbb_lang WHERE lang_iso='es_x_tu'); \
   UPDATE phpbb_config SET config_value='zh_cmn_hans' WHERE config_name='default_lang'; \
   UPDATE phpbb_users SET user_lang='zh_cmn_hans' WHERE user_id=1;"
docker compose exec -T php php ./bin/phpbbcli.php cache:purge
```

Ubuntu existing-install refresh (recommended after `git pull`):

```bash
cd /srv/vpark/vparkbb
docker compose up -d db php nginx
./scripts/bootstrap_production.sh --project vparkbb --skip-install --skip-certbot
```

Language switching behavior:

- Guest users: switch language from the header links (`简体中文 / 繁體中文 / ENGLISH`) on the front page. phpBB stores the selection in cookie `phpbb3_*_lang`.
- Registered users: the same header links remain available after login for quick switching (cookie-based). If no language cookie is set, UCP profile language (`user_lang`) is used.
- Extra dropdown in header: `FRENCH`, `SPANISH`, `ENGLISH GB`.

Supported options:

- English US (`en_us`) - quick header link `ENGLISH`
- English GB (`en`) - dropdown item `ENGLISH GB`
- Simplified Chinese (`zh_cmn_hans`, also accepts alias `zh_cn`)
- Traditional Chinese (`zh_cmn_hant`, also accepts aliases like `zh_tw`)
- French (`fr`)
- Spanish casual honorifics (`es_x_tu`)

## 17) Branding + Footer Text

Current branding target:

- Site title by language:
  - East Asian languages (`zh*`, `ja*`, `ko*`): `维园网`
  - Other languages: `Victoria Park`
- Site subtitle (second line): `VictoriaPark.io`
- Header logo: custom horizontal `Victoria Park` SVG logo (replaces default phpBB logo)
- Footer legal text:
  - `VictoriaPark.io does not represent or guarantee the truthfulness, accuracy, or reliability of any of communications posted by other users.`
  - `Copyright ©2026 victoriapark.io All rights reserved`
- Footer legal text is language-pack driven via:
  - `ext/vpark/glue/language/en/common.php`
  - `ext/vpark/glue/language/en_us/common.php`
  - `ext/vpark/glue/language/fr/common.php`
  - `ext/vpark/glue/language/es_x_tu/common.php`
  - `ext/vpark/glue/language/zh_cmn_hans/common.php`
  - `ext/vpark/glue/language/zh_cmn_hant/common.php`

Keep existing rows:

- `门户 | 城市分区`
- `隐私 | 条款`
- `管理员控制面板`

After any template/logo/language change:

```bash
docker compose exec -T php php ./bin/phpbbcli.php cache:purge
```

If browser still shows old logo/title/footer, do a hard refresh.
