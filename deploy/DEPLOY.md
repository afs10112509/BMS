# Deploy BMS ke Ubuntu (Production)

**Domain:** `bms.adbr.my.id`  
**Server:** `10.147.17.236` (Ubuntu)

## Catatan jaringan penting

IP `10.147.17.236` adalah **IP privat**. Agar bisa diakses **online** dari internet:

1. **DNS** — buat record `A` untuk `bms.adbr.my.id` → **IP publik** router/VPS (bukan 10.x), **atau**
2. **Tailscale/WireGuard** — jika hanya untuk tim internal via VPN, akses lewat jaringan pribadi (DNS bisa internal).

Pastikan port **80** dan **443** terbuka (firewall + port forwarding jika di belakang router).

---

## Langkah 1 — Upload kode ke server

Dari PC Windows (PowerShell), rsync atau scp:

```bash
# Contoh scp (ganti user SSH)
scp -r D:\laragon\www\BMS user@10.147.17.236:/tmp/bms-src
```

Di server:

```bash
sudo rsync -a --delete /tmp/bms-src/ /var/www/bms/
sudo chown -R www-data:www-data /var/www/bms
```

Atau clone Git jika repo sudah di GitHub:

```bash
sudo git clone <url-repo> /var/www/bms
sudo chown -R www-data:www-data /var/www/bms
```

---

## Langkah 2 — Setup server (sekali)

```bash
cd /var/www/bms
sudo bash deploy/ubuntu/01-setup-server.sh
sudo bash deploy/ubuntu/02-setup-database.sh
```

Catat password database `bms_app`.

---

## Langkah 3 — Konfigurasi `.env`

```bash
cd /var/www/bms
sudo cp deploy/.env.production.example .env
sudo nano .env
```

Isi minimal:
- `APP_KEY` → `sudo -u www-data php artisan key:generate`
- `DB_PASSWORD` → password dari langkah 2
- Pastikan `APP_URL` dan `FRONTEND_URL` = `https://bms.adbr.my.id`

---

## Langkah 4 — Install aplikasi

```bash
cd /var/www/bms
sudo bash deploy/ubuntu/03-install-app.sh
```

Script ini akan:
- `composer install --no-dev`
- migrate + seed
- cache config/route/view
- nginx + SSL (certbot)
- queue worker (systemd)
- **backup harian jam 02:00**

---

## Langkah 5 — DNS

Di panel DNS domain `adbr.my.id`:

| Tipe | Nama | Nilai |
|------|------|-------|
| A | bms | IP publik server |

Tunggu propagasi (5–30 menit), lalu:

```bash
sudo certbot --nginx -d bms.adbr.my.id
```

---

## Backup otomatis

- **Lokasi:** `/var/backups/bms/daily/` dan `/weekly/`
- **Jadwal:** setiap hari jam **02:00**
- **Log:** `/var/log/bms/backup.log`
- **Retensi:** 7 harian + 4 mingguan (Senin)

Manual:

```bash
sudo /usr/local/bin/backup-bms-db.sh
```

Restore (contoh):

```bash
gunzip -c /var/backups/bms/daily/bms_db_bms_YYYYMMDD_HHMMSS.dump.gz > /tmp/restore.dump
sudo -u postgres pg_restore -d db_bms -c /tmp/restore.dump
```

---

## Update aplikasi (setelah perubahan kode)

```bash
cd /var/www/bms
sudo -u www-data git pull   # atau rsync ulang
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo systemctl restart bms-queue
```

---

## Keamanan setelah go-live

1. Ganti password semua user (Owner + admin) — jangan pakai `password` dari seeder
2. Pastikan `APP_DEBUG=false`
3. Endpoint `/api/auth/demo-accounts` otomatis **404** di production
4. Copy backup ke Google Drive / NAS (disarankan)

---

## Akses

- **SPA:** https://bms.adbr.my.id/app/
- **API:** https://bms.adbr.my.id/api/

Akun awal dari seeder — **wajib ganti password** sebelum dipakai tim.
