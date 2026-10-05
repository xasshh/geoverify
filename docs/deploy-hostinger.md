# Deploying the Field Enumeration Platform to a Hostinger VPS

A runbook for one Hostinger **KVM 4** (4 vCPU, 16 GB RAM, 200 GB NVMe) in the
**United Kingdom**, serving the field client (`/field`), the supervisor console
(`/console`) and administration (`/admin`). Everything runs on the one machine:
PostgreSQL with PostGIS and h3-pg, Redis, PHP-FPM behind Nginx, a queue worker,
the scheduler and headless Chrome for the printed evidence packs.

Written for Ubuntu 24.04. Commands are run as the `deploy` user unless the
prompt says `root#`. Replace `field.example.ng` with the real domain throughout.

## What this machine runs

| Piece | How | Why it is here |
|---|---|---|
| PostgreSQL 17, PostGIS 3.5, h3-pg | Docker, built from `docker/postgres` | No published image has h3-pg; the repo's image compiles it at a pinned commit |
| Redis 7 | Docker | Queues and cache |
| PHP 8.3 FPM | Ubuntu packages | The application |
| Nginx | Ubuntu packages | HTTPS in front, plus a loopback-only site Chrome prints from |
| Google Chrome | Google's apt repository | `PdfRenderer` prints evidence packs, briefs, certificates and receipts |
| Queue worker | systemd | Scoring captures, refreshing cell progress |
| Scheduler | cron | `schedule:run` every minute |
| Node 22 | NodeSource | Building the front end and the service worker |

Before you start you need: the VPS, a domain with an A record pointing at the
VPS's IP, and the code somewhere the server can fetch it (a private GitHub
repository is simplest; this repository has no remote yet, so push it first).

## 1. First login and hardening

```bash
# From your own machine. Hostinger shows the IP and root password in hPanel.
ssh root@YOUR_VPS_IP
```

```bash
root# apt update && apt upgrade -y
root# timedatectl set-timezone UTC          # the app itself runs in Africa/Lagos
root# adduser deploy && usermod -aG sudo deploy
root# mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/ 2>/dev/null
root# chown -R deploy:deploy /home/deploy/.ssh

# Firewall: SSH, HTTP and HTTPS only. Postgres and Redis are never public.
root# ufw allow OpenSSH && ufw allow 80,443/tcp
root# ufw enable

# Security updates install themselves.
root# apt install -y unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades

# A little swap, so a burst of printing cannot take the database down.
root# fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
root# echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

Add your SSH key for `deploy`, confirm you can log in as `deploy`, then set
`PasswordAuthentication no` in `/etc/ssh/sshd_config` and `systemctl restart ssh`.

## 2. Install the software

```bash
# PHP 8.3 and the extensions this code uses: pgsql, redis, exif (photograph
# metadata), gd, intl, bcmath, zip.
sudo apt install -y nginx git unzip curl ca-certificates \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-gd php8.3-intl \
  php8.3-bcmath php8.3-zip php8.3-mbstring php8.3-xml php8.3-curl php8.3-exif

# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# Node 22
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs

# Docker, for PostgreSQL and Redis
curl -fsSL https://get.docker.com | sudo sh && sudo usermod -aG docker deploy
# log out and back in so the docker group applies

# Google Chrome. The Ubuntu "chromium" package is a snap, which runs badly
# under php-fpm; Google's .deb installs to /usr/bin/google-chrome-stable,
# which PdfRenderer looks for.
curl -fsSL https://dl.google.com/linux/linux_signing_key.pub | sudo gpg --dearmor -o /usr/share/keyrings/google.gpg
echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google.gpg] https://dl.google.com/linux/chrome/deb/ stable main" \
  | sudo tee /etc/apt/sources.list.d/google-chrome.list
sudo apt update && sudo apt install -y google-chrome-stable
```

## 3. Database and Redis

Create `/srv/geoverify-data/compose.yml`. It builds the repository's own
Postgres image and binds both services to the loopback interface only.

```bash
sudo mkdir -p /srv/geoverify-data && sudo chown deploy:deploy /srv/geoverify-data
openssl rand -base64 24   # use this as DB_PASSWORD below and in .env
```

```yaml
# /srv/geoverify-data/compose.yml
name: geoverify-data

services:
  postgres:
    build:
      context: /var/www/geoverify/docker/postgres
      args:
        POSTGRES_VERSION: "17"
        POSTGIS_VERSION: "3.5"
    restart: unless-stopped
    environment:
      POSTGRES_DB: geoverify
      POSTGRES_USER: geoverify
      POSTGRES_PASSWORD: "PASTE_THE_GENERATED_PASSWORD"
    # shared_buffers a quarter of the machine's memory, as PostgreSQL advises.
    command: >-
      postgres -c shared_buffers=4GB -c effective_cache_size=10GB
               -c work_mem=32MB -c maintenance_work_mem=1GB -c max_connections=100
    ports:
      - "127.0.0.1:5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes"]
    ports:
      - "127.0.0.1:6379:6379"
    volumes:
      - redisdata:/data

volumes:
  pgdata:
  redisdata:
```

It builds from the checked-out code, so clone first (section 4), then:

```bash
cd /srv/geoverify-data && docker compose up -d --build
# The extension check the local stack uses: must print a number above zero.
docker compose exec postgres psql -U geoverify -d geoverify -tAc \
  "select count(*) from h3_polygon_to_cells(st_geomfromtext('POLYGON((7.44 9.03,7.50 9.03,7.50 9.08,7.44 9.08,7.44 9.03))',4326),9)"
```

If `H3PG_COMMIT` ever changes in `docker/postgres/Dockerfile`, rebuild this
image; CI pins the same commit.

## 4. The application

```bash
sudo mkdir -p /var/www && sudo chown deploy:www-data /var/www
cd /var/www && git clone git@github.com:YOUR_ORG/geoverify.git
cd geoverify
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # also writes public/sw.js, the offline worker
cp .env.example .env && php artisan key:generate
```

Edit `.env`. The lines that matter in production:

```dotenv
APP_NAME=GeoVerify
APP_ENV=production
APP_DEBUG=false
APP_URL=https://field.example.ng

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=geoverify
DB_USERNAME=geoverify
DB_PASSWORD=PASTE_THE_GENERATED_PASSWORD

SESSION_DRIVER=database
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1

# Photographs on the VPS's own disk, private, behind signed short-lived links.
# To use S3-compatible object storage instead, set MEDIA_DISK_DRIVER=s3 and the
# AWS_* values (AWS_ENDPOINT for a non-AWS provider).
MEDIA_DISK_DRIVER=local

# Printing: Chrome fetches the document from the loopback site in section 5,
# not from the public HTTPS address, whose certificate would not match 127.0.0.1.
CHROMIUM_BINARY=/usr/bin/google-chrome-stable
CHROMIUM_BASE_URL=http://127.0.0.1:8081

# Nginx on this machine is the only thing in front, so no proxy is trusted.
# If you later put Cloudflare in front, set this (see config/app.php).
TRUSTED_PROXIES=

# Password reset emails for staff.
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=no-reply@field.example.ng
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@field.example.ng

# Sign-in and claim codes by text. Without this nobody new can sign in to the
# portal or Enumerate: the log driver refuses to run in production. From the
# Termii dashboard: the API key, your account's base URL, and a sender ID
# approved for the dnd channel (approval takes a few working days).
SMS_DRIVER=termii
TERMII_BASE_URL=https://v3.api.termii.com
TERMII_API_KEY=...
TERMII_SENDER_ID=GeoVerify
TERMII_CHANNEL=dnd
```

Then:

```bash
php artisan migrate --force
php artisan geoverify:taxonomy-load
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

Never run `db:seed` here: the seeders create demo staff with the password
`password`.

## 5. Nginx, HTTPS and PHP-FPM

`/etc/nginx/sites-available/geoverify`:

```nginx
# The public site.
server {
    listen 80;
    server_name field.example.ng;
    root /var/www/geoverify/public;
    index index.php;

    # Photographs are up to 4 MB each; leave room for the form around them.
    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # The offline worker must never be cached by the browser, or an officer
    # keeps an old app after an update.
    location = /sw.js {
        add_header Cache-Control "no-cache";
        try_files $uri =404;
    }

    location /build/ {
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 180;
    }
}

# The loopback-only site Chrome prints from. Never reachable from outside:
# bound to 127.0.0.1, and the print routes refuse any other address anyway.
server {
    listen 127.0.0.1:8081;
    server_name 127.0.0.1;
    root /var/www/geoverify/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 180;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/geoverify /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# HTTPS. Required, not optional: the field client's offline worker only runs
# on an HTTPS page. Certbot renews on its own.
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d field.example.ng
```

PHP-FPM: printing needs the application to answer a second request while the
first is still open (Chrome fetching the document it was asked to print), so
there must always be spare workers. In `/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
pm = dynamic
pm.max_children = 16
pm.start_servers = 4
pm.min_spare_servers = 4
pm.max_spare_servers = 8
pm.max_requests = 500
```

And in `/etc/php/8.3/fpm/php.ini`: `upload_max_filesize = 8M`,
`post_max_size = 10M`, `max_execution_time = 180`, `memory_limit = 512M`.
Then `sudo systemctl restart php8.3-fpm`.

## 6. Queue worker and scheduler

`/etc/systemd/system/geoverify-queue.service`:

```ini
[Unit]
Description=GeoVerify queue worker
After=network.target docker.service

[Service]
User=deploy
Group=www-data
WorkingDirectory=/var/www/geoverify
ExecStart=/usr/bin/php artisan queue:work redis --sleep=1 --tries=3 --timeout=300 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now geoverify-queue
( crontab -l 2>/dev/null; echo "* * * * * cd /var/www/geoverify && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -
```

The schedule includes marketplace jobs (`orders:sweep-sla`,
`orders:release-delivered`, `geoverify:reconcile-ledger`). With no marketplace
in use they find nothing; `geoverify:reconcile-ledger` exits with an error
every morning because no Paystack key is set, which is harmless but will show
in the logs.

## 7. The first administrator

Staff are created by an admin, so the very first admin is made by hand, once.
The password is typed at a hidden prompt and never appears in shell history:

```bash
cd /var/www/geoverify
read -s -p "Admin password: " GV_PW; echo
GV_PW="$GV_PW" php artisan tinker --execute='
App\Models\User::query()->create([
    "name" => "Your Name",
    "email" => "you@field.example.ng",
    "password" => getenv("GV_PW"),
    "role" => App\Enums\Role::Admin,
    "status" => App\Models\User::STATUS_ACTIVE,
]);'
unset GV_PW
```

Sign in at `https://field.example.ng/login`. Everyone else (supervisors,
officers) is added from `/admin/people`.

## 8. Loading the ground

The register is built by commands, in this order (see `docs/geodata.md` for
where each file comes from). Copy the files up first, for example with
`scp -r geodata/ deploy@YOUR_VPS_IP:/var/www/geoverify/storage/geodata/`.

```bash
# Boundaries first, always: states, then LGAs, then wards, one file each.
php artisan geoverify:boundaries-load storage/geodata/states.geojson --preset=ocha-state
php artisan geoverify:boundaries-load storage/geodata/lgas.geojson --preset=ocha-lga
php artisan geoverify:boundaries-load storage/geodata/wards.geojson --preset=grid3-ward
php artisan geoverify:coverage-create --lga-code=...
php artisan geoverify:grid-generate <area>
php artisan geoverify:footprints-ingest <area> --path=storage/geodata/<footprints>
php artisan geoverify:roads-ingest --path=storage/geodata/<roads>
php artisan geoverify:pack-build <area>            # the offline map officers download
```

One LGA's footprints are about 120 MB in the database and its offline pack
about 70 MB; both are well within the plan.

## 9. Backups

Hostinger's free weekly backup restores the whole machine to a week-old
state, which on its own could lose a week of captures in the middle of a
campaign. So the database and the photographs are also copied off the
machine every night.

`/home/deploy/backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
STAMP=$(date -u +%Y%m%d-%H%M)
DEST=/home/deploy/backups
mkdir -p "$DEST"

# The database, in PostgreSQL's own compressed format.
docker compose -f /srv/geoverify-data/compose.yml exec -T postgres \
  pg_dump -U geoverify -Fc geoverify > "$DEST/geoverify-$STAMP.dump"

# Photographs and offline packs.
tar -czf "$DEST/media-$STAMP.tgz" -C /var/www/geoverify/storage/app private

# Off the machine. Configure an rclone remote once with `rclone config`
# (Backblaze B2, Cloudflare R2, AWS S3 or Google Drive all work).
rclone copy "$DEST" offsite:geoverify-backups/

# Two weeks kept locally.
find "$DEST" -type f -mtime +14 -delete
```

```bash
sudo apt install -y rclone && rclone config
chmod +x /home/deploy/backup.sh
( crontab -l; echo "30 1 * * * /home/deploy/backup.sh >> /home/deploy/backup.log 2>&1" ) | crontab -
```

Test a restore before the first campaign, not during it:
`pg_restore -U geoverify -d geoverify_restore_test --clean geoverify-XXXX.dump`
inside the container, against a scratch database.

Hostinger's **Daily auto-backup** add-on is an alternative that snapshots the
whole machine every day. It is simpler, but it costs more per month than the
server itself; the script above does the essential part for the price of the
off-site storage.

## 10. Checking it works

1. `https://field.example.ng/` shows the spatial stack health page: PostGIS
   and H3 both green.
2. Sign in as the admin, create a supervisor and an officer, give the officer
   cells from `/console/coverage`.
3. On a phone, sign in as the officer, download the offline map from Sync &
   device, and "Add to home screen".
4. Turn on flight mode, capture a building with photographs, reopen the app,
   move between Today, Inbox and My records. Turn flight mode off: the record
   and photos reach the console by themselves.
5. From `/console/exports`, print an evidence pack. If it times out, PHP-FPM
   has too few spare workers or `CHROMIUM_BASE_URL` is wrong.

## 11. Deploying an update

```bash
cd /var/www/geoverify
php artisan down --retry=15
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
sudo systemctl reload php8.3-fpm
php artisan up
```

Officers are not interrupted: the worker waits for them to accept the update
(`registerType: 'prompt'`), so nobody's app reloads partway through a building.

## About "enumeration only"

This is one application. Deployed as above, the business portal (`/portal`),
the public directory (`/directory`) and the investor portal (`/invest`) are
also reachable at this address, even though nobody uses them. No payment
provider is configured, so nothing can be bought. Switching those surfaces off
in production would take a small change to the code (a setting that stops
their routes being registered); it has not been made yet.
