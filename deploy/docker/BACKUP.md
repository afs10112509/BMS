# Backup database BMS (Docker)

## Lokal
- Skrip: `deploy/docker/backup-bms-db.sh`
- Folder: `/opt/backups/bms/daily/` dan `weekly/`
- Retensi: 7 hari (daily), 4 minggu (weekly)
- Jadwal: cron jam **02:00**

## Google Drive
1. Setup rclone sekali: `bash deploy/docker/setup-rclone-gdrive.sh`
2. Lalu `rclone config` (ikuti panduan di skrip) — remote name harus **`gdrive`**
3. Backup berikutnya otomatis upload ke `gdrive:BMS-Backups/daily/`

## Manual
```bash
/opt/apps/bms/deploy/docker/backup-bms-db.sh
```

## Restore (contoh)
```bash
gunzip -c /opt/backups/bms/daily/bms_db_bms_YYYYMMDD_HHMMSS.dump.gz > /tmp/restore.dump
docker exec -i postgres pg_restore -U bms_app -d db_bms --clean --if-exists < /tmp/restore.dump
```
