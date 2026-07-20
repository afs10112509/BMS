#!/usr/bin/env bash
# Backup harian PostgreSQL BMS + retensi.
# Install: sudo cp deploy/ubuntu/backup-bms-db.sh /usr/local/bin/backup-bms-db.sh
#          sudo chmod +x /usr/local/bin/backup-bms-db.sh
# Cron:    0 2 * * * /usr/local/bin/backup-bms-db.sh >> /var/log/bms/backup.log 2>&1

set -euo pipefail

ENV_FILE="${BMS_ENV_FILE:-/var/www/bms/.env}"
BACKUP_ROOT="${BMS_BACKUP_ROOT:-/var/backups/bms}"
DAILY_DIR="${BACKUP_ROOT}/daily"
WEEKLY_DIR="${BACKUP_ROOT}/weekly"
KEEP_DAILY="${BMS_KEEP_DAILY:-7}"
KEEP_WEEKLY="${BMS_KEEP_WEEKLY:-4}"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "[$(date -Is)] ERROR: .env tidak ditemukan: ${ENV_FILE}" >&2
  exit 1
fi

get_env() {
  grep -E "^${1}=" "${ENV_FILE}" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

DB_DATABASE="$(get_env DB_DATABASE)"
DB_USERNAME="$(get_env DB_USERNAME)"
DB_PASSWORD="$(get_env DB_PASSWORD)"
DB_HOST="$(get_env DB_HOST)"
DB_PORT="$(get_env DB_PORT)"

: "${DB_DATABASE:?DB_DATABASE kosong}"
: "${DB_USERNAME:?DB_USERNAME kosong}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"

STAMP="$(date +%Y%m%d_%H%M%S)"
DAY_OF_WEEK="$(date +%u)"
FILE="${DAILY_DIR}/bms_${DB_DATABASE}_${STAMP}.dump"

mkdir -p "${DAILY_DIR}" "${WEEKLY_DIR}"

export PGPASSWORD="${DB_PASSWORD:-}"

echo "[$(date -Is)] Backup ${DB_DATABASE} -> ${FILE}"
pg_dump \
  -h "${DB_HOST}" \
  -p "${DB_PORT}" \
  -U "${DB_USERNAME}" \
  -Fc \
  --no-owner \
  --no-acl \
  "${DB_DATABASE}" > "${FILE}"

gzip -f "${FILE}"
FILE="${FILE}.gz"

# Salin ke weekly setiap Senin (day 1)
if [[ "${DAY_OF_WEEK}" == "1" ]]; then
  cp "${FILE}" "${WEEKLY_DIR}/$(basename "${FILE}")"
fi

find "${DAILY_DIR}" -name '*.dump.gz' -type f -mtime +"${KEEP_DAILY}" -delete
find "${WEEKLY_DIR}" -name '*.dump.gz' -type f -mtime +$((KEEP_WEEKLY * 7)) -delete

unset PGPASSWORD
echo "[$(date -Is)] Selesai. Ukuran: $(du -h "${FILE}" | cut -f1)"
