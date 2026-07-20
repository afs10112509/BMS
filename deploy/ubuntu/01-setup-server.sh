#!/usr/bin/env bash
# Setup awal Ubuntu untuk BMS (jalankan sebagai root atau sudo).
# Usage: sudo bash deploy/ubuntu/01-setup-server.sh

set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

echo "==> Update paket..."
apt-get update -y
apt-get upgrade -y

echo "==> Install dependensi..."
apt-get install -y \
  nginx \
  postgresql \
  postgresql-contrib \
  certbot \
  python3-certbot-nginx \
  git \
  unzip \
  curl \
  acl \
  ufw

echo "==> Install PHP 8.3..."
apt-get install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
  php8.3-fpm \
  php8.3-cli \
  php8.3-pgsql \
  php8.3-mbstring \
  php8.3-xml \
  php8.3-curl \
  php8.3-zip \
  php8.3-bcmath \
  php8.3-intl \
  php8.3-gd

echo "==> Install Composer..."
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> Buat direktori aplikasi & backup..."
mkdir -p /var/www/bms
mkdir -p /var/backups/bms/daily
mkdir -p /var/backups/bms/weekly
mkdir -p /var/log/bms
chown -R www-data:www-data /var/www/bms
chmod 750 /var/backups/bms

echo "==> Firewall (SSH + HTTP + HTTPS)..."
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable || true

echo "==> Selesai setup server."
echo "    Langkah berikut: bash deploy/ubuntu/02-setup-database.sh"
