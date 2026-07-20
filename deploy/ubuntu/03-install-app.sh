#!/usr/bin/env bash
# Deploy / update kode BMS ke /var/www/bms
# Jalankan dari root repo setelah git clone/rsync.
# Usage: sudo bash deploy/ubuntu/03-install-app.sh

set -euo pipefail

APP_DIR="/var/www/bms"
DOMAIN="${BMS_DOMAIN:-bms.adbr.my.id}"

if [[ ! -f "${APP_DIR}/artisan" ]]; then
  echo "Salin kode ke ${APP_DIR} dulu (git clone / rsync)." >&2
  exit 1
fi

cd "${APP_DIR}"

echo "==> Composer install (production)..."
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction

if [[ ! -f .env ]]; then
  echo "==> Buat .env dari template production..."
  cp deploy/.env.production.example .env
  echo "    EDIT .env: DB_PASSWORD, APP_KEY, dll."
  sudo -u www-data php artisan key:generate
fi

echo "==> Migrate & cache..."
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force --class=DatabaseSeeder || true
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

echo "==> Permission storage & bootstrap/cache..."
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

echo "==> Nginx site..."
cp deploy/ubuntu/nginx-bms.conf /etc/nginx/sites-available/bms
ln -sf /etc/nginx/sites-available/bms /etc/nginx/sites-enabled/bms
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "==> Queue worker (systemd)..."
cp deploy/ubuntu/bms-queue.service /etc/systemd/system/bms-queue.service
systemctl daemon-reload
systemctl enable bms-queue
systemctl restart bms-queue

echo "==> Backup script & cron..."
install -m 755 deploy/ubuntu/backup-bms-db.sh /usr/local/bin/backup-bms-db.sh
CRON_LINE='0 2 * * * /usr/local/bin/backup-bms-db.sh >> /var/log/bms/backup.log 2>&1'
( crontab -l 2>/dev/null | grep -v backup-bms-db || true; echo "${CRON_LINE}" ) | crontab -

echo "==> SSL (Let's Encrypt)..."
BMS_LETSENCRYPT_EMAIL="${BMS_LETSENCRYPT_EMAIL:-admin@adbr.my.id}"
if certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m "${BMS_LETSENCRYPT_EMAIL}" --redirect 2>/dev/null; then
  echo "    SSL berhasil."
else
  echo "    SSL gagal otomatis (DNS mungkin belum propagate). Jalankan manual:"
  echo "    certbot --nginx -d ${DOMAIN} -m ${BMS_LETSENCRYPT_EMAIL}"
fi

echo "==> Selesai. Buka https://${DOMAIN}/app/"
