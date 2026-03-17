# VictoriaPark.io Production Deployment Runbook

## Server Requirements

- Ubuntu 22.04+ (or Debian 12+)
- Docker Engine 24+ & Docker Compose v2
- 2 GB RAM minimum (4 GB recommended)
- Domain DNS: `victoriapark.io` and `www.victoriapark.io` pointing to server IP

---

## 1. Initial Server Setup

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
newgrp docker

# Install Docker Compose plugin (if not bundled)
sudo apt install -y docker-compose-plugin

# Verify
docker --version
docker compose version
```

## 2. Clone Repository

```bash
cd /opt
sudo git clone https://github.com/finalverse/vparkbb.git victoriapark
sudo chown -R $USER:$USER /opt/victoriapark
cd /opt/victoriapark
```

## 3. Configure Environment

```bash
# Copy and edit .env (already exists in repo, update passwords for production!)
cp .env .env.bak
nano .env

# IMPORTANT: Change these in .env for production:
#   DB_PASSWORD=<generate-new-strong-password>
#   DB_ROOT_PASSWORD=<generate-new-strong-password>
```

Generate strong passwords:
```bash
openssl rand -hex 24  # Run twice, one for each password
```

## 4. Configure Nginx for Production

The repo contains two nginx configs:
- `nginx.prod.conf` - Production (SSL, domain-based, HTTP/2)
- `nginx.local.conf` - Local development (HTTP-only, localhost)

```bash
# Use production config
cp nginx.prod.conf nginx.conf
```

The `docker-compose.yml` mounts `./nginx.conf` into the nginx container.

## 5. Obtain SSL Certificates (First Time)

Before starting with SSL, temporarily use HTTP-only config to get certs:

```bash
# Create a minimal nginx config for cert issuance
cat > nginx.conf << 'EOF'
server {
    listen 80;
    server_name victoriapark.io www.victoriapark.io;
    root /var/www/html;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type text/plain;
    }

    location = /healthz {
        access_log off;
        default_type text/plain;
        return 200 "ok\n";
    }

    location = /php-fpm-ping {
        allow 127.0.0.1;
        deny all;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /php-fpm-ping;
        fastcgi_pass php:9000;
    }

    location / {
        try_files $uri $uri/ /app.php?$query_string;
    }

    location ~ [^/]\.php(/|$) {
        fastcgi_split_path_info ^(.+?\.php)(/.*)$;
        try_files $fastcgi_script_name =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass php:9000;
        fastcgi_read_timeout 120s;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\.(?!well-known).* { deny all; }
    location ~* /(cache|files|store|vendor)/.*\.php$ { deny all; }
    location ~* \.(sql|twig|tpl|yaml|yml|md|sh|inc|dist)$ { deny all; }
}
EOF

# Start services
docker compose up -d

# Wait for services to be healthy
docker compose ps

# Request certificates
docker compose run --rm certbot certonly \
  --webroot \
  --webroot-path /var/www/certbot \
  -d victoriapark.io \
  -d www.victoriapark.io \
  --email ops@victoriapark.io \
  --agree-tos \
  --no-eff-email

# Switch to production SSL config
cp nginx.prod.conf nginx.conf

# Reload nginx
docker compose exec nginx nginx -s reload
```

## 6. Start Services

```bash
cd /opt/victoriapark
docker compose up -d

# Verify all services are healthy
docker compose ps
docker compose logs --tail=20
```

## 7. Post-Deploy Scripts

Run these after first deployment or after database changes:

```bash
# Seed forums (first time only)
docker compose exec php php /var/www/html/scripts/phpbb_seed_forums.php

# Fix board settings (registration, permissions, languages)
docker compose exec php php /var/www/html/scripts/fix_board_settings.php

# Populate forum descriptions
docker compose exec php php /var/www/html/scripts/migrate_forum_descriptions.php

# Fetch RSS breaking news
docker compose exec php php /var/www/html/scripts/fetch_rss_news.php

# Set admin password
docker compose exec php php /var/www/html/bin/phpbbcli.php user:password admin YourNewPassword
```

## 8. Cron Jobs

Add these to the server's crontab (`crontab -e`):

```cron
# Fetch RSS breaking news every 10 minutes
*/10 * * * * cd /opt/victoriapark && docker compose exec -T php php /var/www/html/scripts/fetch_rss_news.php >> /var/log/vpark-rss.log 2>&1

# Renew SSL certificates (monthly)
0 3 1 * * cd /opt/victoriapark && docker compose run --rm certbot renew --quiet && docker compose exec nginx nginx -s reload >> /var/log/vpark-certbot.log 2>&1

# Database backup (daily at 2 AM)
0 2 * * * cd /opt/victoriapark && mkdir -p backups/db && docker compose exec -T db mariadb-dump -u root -p"$(grep DB_ROOT_PASSWORD .env | cut -d= -f2)" phpbb | gzip > backups/db/phpbb-$(date +\%Y\%m\%d).sql.gz 2>&1

# Clean old backups (keep 14 days)
0 4 * * * find /opt/victoriapark/backups/db -name "*.sql.gz" -mtime +14 -delete
```

## 9. Deploying Updates

```bash
cd /opt/victoriapark

# Pull latest code
git pull origin main

# Rebuild PHP image if Dockerfile changed
docker compose build php

# Restart services
docker compose up -d

# Purge phpBB cache
docker compose exec php php /var/www/html/bin/phpbbcli.php cache:purge

# Check health
docker compose ps
curl -s https://www.victoriapark.io/healthz
```

## 10. Ad Management

### Homepage Ad Slot
Configure via environment variables in `.env` or `docker-compose.yml`:

```env
# Image-based ad
VPARK_HOME_AD_URL=https://advertiser.example.com
VPARK_HOME_AD_IMAGE_URL=https://cdn.example.com/ad-banner.jpg
VPARK_HOME_AD_TITLE=Advertiser Name
VPARK_HOME_AD_DESC=Ad description text

# Text-only ad (omit IMAGE_URL)
VPARK_HOME_AD_TITLE=Your Ad Title
VPARK_HOME_AD_DESC=Your ad description here.
```

After changing env vars: `docker compose up -d` to restart.

When no env vars are set, demo/placeholder ads appear automatically.

### Header Ad Slot
The header right-side ad is controlled by the extension's language files:
- Edit `ext/vpark/glue/language/{lang}/common.php`
- Keys: `VPARK_HEADER_AD_TITLE` and `VPARK_HEADER_AD_DESC`

### Sub-Forum Ads
Sub-forum moderators can manage ads through the phpBB ACP:
1. Go to `ACP > Forums > Edit Forum`
2. Use the forum description field for ad content
3. Or create a sticky announcement topic for ad placement

## 11. Monitoring & Troubleshooting

```bash
# Check service status
docker compose ps

# View logs
docker compose logs -f nginx
docker compose logs -f php
docker compose logs -f db

# Check nginx health
curl -s http://localhost/healthz

# Check PHP-FPM
docker compose exec nginx wget -q -O - http://127.0.0.1/php-fpm-ping

# Enter PHP container for debugging
docker compose exec php bash

# Check disk usage
docker system df
du -sh /opt/victoriapark/backups/

# Restart a single service
docker compose restart nginx
docker compose restart php
```

## 12. SSL Certificate Renewal

Certificates auto-renew via the cron job above. To manually renew:

```bash
cd /opt/victoriapark
docker compose run --rm certbot renew
docker compose exec nginx nginx -s reload
```

## 13. Database Operations

```bash
# Connect to database CLI
docker compose exec db mariadb -u phpbb -p phpbb

# Manual backup
docker compose exec -T db mariadb-dump -u root -p"$(grep DB_ROOT_PASSWORD .env | cut -d= -f2)" phpbb > backup.sql

# Restore from backup
cat backup.sql | docker compose exec -T db mariadb -u root -p"$(grep DB_ROOT_PASSWORD .env | cut -d= -f2)" phpbb
```

## 14. Firewall Setup (UFW)

```bash
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP (for cert renewal + redirect)
sudo ufw allow 443/tcp   # HTTPS
sudo ufw enable
sudo ufw status
```

## 15. Key File Locations

| File | Purpose |
|------|---------|
| `docker-compose.yml` | Service definitions |
| `.env` | Database credentials, domain settings |
| `nginx.conf` | Active nginx config (mounted into container) |
| `nginx.prod.conf` | Production nginx template |
| `nginx.local.conf` | Local dev nginx template |
| `ext/vpark/glue/` | Custom extension (portal, ads, RSS, language switching) |
| `styles/victoriaclassic/` | Custom theme |
| `scripts/` | Admin scripts (seeding, RSS, settings) |
| `cache/` | phpBB template/data cache (safe to delete contents) |

## 16. Quick Reference Commands

```bash
# Purge all caches
docker compose exec php php /var/www/html/bin/phpbbcli.php cache:purge

# Reset admin password
docker compose exec php php /var/www/html/bin/phpbbcli.php user:password admin NewPassword123

# Fetch fresh RSS news
docker compose exec -T php php /var/www/html/scripts/fetch_rss_news.php

# Fix permissions/registration/languages
docker compose exec php php /var/www/html/scripts/fix_board_settings.php

# Full restart
docker compose down && docker compose up -d
```
