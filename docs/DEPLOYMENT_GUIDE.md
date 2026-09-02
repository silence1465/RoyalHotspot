# Deployment Guide

Target: Ubuntu 24.04 LTS VPS. Two subdomains — `api.yourdomain.com`
(Laravel backend) and `app.yourdomain.com` (React frontend, the actual
captive-portal + admin UI customers and staff use). Config files referenced
below live in `deploy/`.

## 1. Base packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-bcmath php8.3-zip \
  php8.3-gd unzip git supervisor certbot python3-certbot-nginx
```

**Optional — Royal WiFi PDF voucher OCR fallback.** Only needed if
uploaded voucher PDFs turn out to be scanned/image-based rather than
digitally generated (a MikroTik voucher export almost certainly won't
be, so start without this and add it only if `VoucherPdfExtractionService`
reports thin extraction on a real upload):

```bash
sudo apt install -y poppler-utils tesseract-ocr
```

Then set `VOUCHER_PDF_OCR_ENABLED=true` in `backend/.env`. Extraction
degrades gracefully without these installed — it just skips OCR and
returns whatever normal text extraction found, so this is safe to leave
off initially.

Install Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Install Node (for building the frontend — you can also build it on your
own machine and just `scp` the `dist/` folder up instead of installing
Node on the VPS):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

## 2. MySQL setup

```bash
sudo mysql_secure_installation
sudo mysql -u root -p
```

```sql
CREATE DATABASE hotspot_billing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hotspot_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
GRANT ALL PRIVILEGES ON hotspot_billing.* TO 'hotspot_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 3. Clone and configure the backend

```bash
sudo mkdir -p /var/www/hotspot-billing
sudo chown $USER:$USER /var/www/hotspot-billing
git clone <your-repo-url> /var/www/hotspot-billing
cd /var/www/hotspot-billing/backend

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:
- `APP_ENV=production`, `APP_DEBUG=false`
- `APP_URL=https://api.yourdomain.com`
- `DB_*` — match what you created in step 2
- `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_CALLBACK_URL=https://app.yourdomain.com/payment/callback`
- `QUEUE_CONNECTION=database` (see why in `deploy/supervisor-worker.conf` — this is not optional, the queue worker is how MikroTik provisioning actually happens)
- `SANCTUM_STATEFUL_DOMAINS` — not actually used by this token-based setup (see `bootstrap/app.php`), safe to leave as-is
- `DEFAULT_ADMIN_EMAIL` / `DEFAULT_ADMIN_PASSWORD` — set a real password here before seeding; don't run the seeder with this blank in production (it'll generate and print a random one, which is safer than a blank, but setting your own is cleaner)
- `FRONTEND_URL=https://app.yourdomain.com`

```bash
php artisan migrate --force
php artisan db:seed --force
```

Set correct ownership/permissions:

```bash
sudo chown -R www-data:www-data /var/www/hotspot-billing/backend
sudo chmod -R 775 /var/www/hotspot-billing/backend/storage \
  /var/www/hotspot-billing/backend/bootstrap/cache
```

Cache config for production (re-run after every `.env` or route change):

```bash
php artisan config:cache
php artisan route:cache
```

## 4. Queue worker (Supervisor)

Copy `deploy/supervisor-worker.conf` to `/etc/supervisor/conf.d/` (update
the path inside if your clone location differs from `/var/www/hotspot-billing`),
then:

```bash
sudo mkdir -p /var/log/hotspot-billing
sudo cp deploy/supervisor-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start hotspot-billing-worker:*
```

Verify it's actually running:

```bash
sudo supervisorctl status hotspot-billing-worker:*
```

## 5. Scheduler (cron)

See `deploy/crontab.txt` — install both entries with `crontab -e` (run as
`www-data`, or adjust the `user=` if you're installing under root's
crontab). The first entry is what actually makes `subscriptions:expire`
run every minute; without it, nothing expires regardless of how correct
the command itself is (see Phase 10 notes in `README.md`).

## 6. Nginx

Copy both configs, updating `server_name` and the `ssl_certificate` paths
(left commented until certbot fills them in):

```bash
sudo cp deploy/nginx-api.conf /etc/nginx/sites-available/hotspot-billing-api
sudo cp deploy/nginx-app.conf /etc/nginx/sites-available/hotspot-billing-app
sudo ln -s /etc/nginx/sites-available/hotspot-billing-api /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/hotspot-billing-app /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 7. SSL (Certbot)

```bash
sudo certbot --nginx -d api.yourdomain.com -d app.yourdomain.com
```

Certbot rewrites both Nginx configs in place to add the 443 server block
and certificate paths, and sets up auto-renewal via a systemd timer
(`systemctl status certbot.timer` to confirm).

## 8. Frontend build

```bash
cd /var/www/hotspot-billing/frontend
cp .env.example .env
```

Edit `.env`: `VITE_API_BASE_URL=https://api.yourdomain.com/api/v1`

```bash
npm install
npm run build
```

`dist/` is what `deploy/nginx-app.conf` serves — no further steps needed,
Nginx points straight at it.

## 9. WireGuard + MikroTik

Covered in full in `WIREGUARD_SETUP.md` — the short version: the VPS runs
a WireGuard server, each MikroTik router is a WireGuard peer, and
`routers.wireguard_ip` in the database is that peer's tunnel IP, not its
public or LAN IP.

## 10. Verify end-to-end

- `curl https://api.yourdomain.com/up` → Laravel's default health route,
  should return 200
- Visit `https://app.yourdomain.com/admin/login`, log in with the seeded
  admin account
- Add a router (Phase 5 UI), hit "Test Connection" — this is the first
  real check that WireGuard + RouterOS API-SSL are actually wired up
  correctly
- Create a package, generate a test voucher (Phase 11), redeem it as a
  test customer account, confirm the queue worker actually enables a
  hotspot user (check `mikrotik_logs` in the admin Logs page)

If "Test Connection" fails, see `PRODUCTION_SECURITY.md` for the RouterOS
API-SSL certificate setup — this is the most common thing to get wrong
and it fails silently otherwise (the router just refuses the connection).

## Security checklist before going live

See `PRODUCTION_SECURITY.md` in full, but at minimum: `APP_DEBUG=false`,
router `api-ssl` enabled and plain API (8728) disabled on every router,
MikroTik API restricted to the WireGuard interface only, VPS firewall
configured, database backups actually running (verify a backup file shows
up after the first cron tick, not just that the cron entry exists).
