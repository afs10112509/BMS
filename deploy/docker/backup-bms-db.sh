#!/usr/bin/env bash
# Backup harian PostgreSQL BMS (Docker) + retensi + upload Google Drive (rclone).
#
# Cron (user dbr):
#   0 2 * * * /opt/apps/bms/deploy/docker/backup-bms-db.sh >> /opt/backups/bms/backup.log 2>&1
#
# Env override:
#   BMS_ENV_FILE, BMS_BACKUP_ROOT, BMS_KEEP_DAILY, BMS_KEEP_WEEKLY
#   BMS_RCLONE_REMOTE (default: gdrive:BMS-Backups) — set kosong untuk skip Drive

set -euo pipefail

ENV_FILE="${BMS_ENV_FILE:-/opt/apps/bms/.env}"
BACKUP_ROOT="${BMS_BACKUP_ROOT:-/opt/backups/bms}"
DAILY_DIR="${BACKUP_ROOT}/daily"
WEEKLY_DIR="${BACKUP_ROOT}/weekly"
KEEP_DAILY="${BMS_KEEP_DAILY:-7}"
KEEP_WEEKLY="${BMS_KEEP_WEEKLY:-4}"
PG_CONTAINER="${BMS_PG_CONTAINER:-postgres}"
RCLONE_REMOTE="${BMS_RCLONE_REMOTE:-gdrive:BMS-Backups}"

log() { echo "[$(date -Is)] $*"; }

if [[ ! -f "${ENV_FILE}" ]]; then
  log "ERROR: .env tidak ditemukan: ${ENV_FILE}" >&2
  exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -qx "${PG_CONTAINER}"; then
  log "ERROR: container ${PG_CONTAINER} tidak berjalan" >&2
  exit 1
fi

get_env() {
  grep -E "^${1}=" "${ENV_FILE}" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

DB_DATABASE="$(get_env DB_DATABASE)"
DB_USERNAME="$(get_env DB_USERNAME)"
DB_PASSWORD="$(get_env DB_PASSWORD)"

: "${DB_DATABASE:?DB_DATABASE kosong}"
: "${DB_USERNAME:?DB_USERNAME kosong}"

STAMP="$(date +%Y%m%d_%H%M%S)"
DAY_OF_WEEK="$(date +%u)"
BASE="bms_${DB_DATABASE}_${STAMP}.dump"
FILE="${DAILY_DIR}/${BASE}"

mkdir -p "${DAILY_DIR}" "${WEEKLY_DIR}"

log "Backup ${DB_DATABASE} via docker://${PG_CONTAINER} -> ${FILE}.gz"
docker exec -e PGPASSWORD="${DB_PASSWORD}" "${PG_CONTAINER}" \
  pg_dump -U "${DB_USERNAME}" -d "${DB_DATABASE}" -Fc --no-owner --no-acl \
  > "${FILE}"

gzip -f "${FILE}"
FILE="${FILE}.gz"

if [[ "${DAY_OF_WEEK}" == "1" ]]; then
  cp "${FILE}" "${WEEKLY_DIR}/$(basename "${FILE}")"
  log "Salinan weekly: ${WEEKLY_DIR}/$(basename "${FILE}")"
fi

find "${DAILY_DIR}" -name 'bms_*.dump.gz' -type f -mtime +"${KEEP_DAILY}" -delete
find "${WEEKLY_DIR}" -name 'bms_*.dump.gz' -type f -mtime +$((KEEP_WEEKLY * 7)) -delete

log "Lokal selesai. Ukuran: $(du -h "${FILE}" | cut -f1)"

# --- Google Drive (opsional) ---
if [[ -n "${RCLONE_REMOTE}" ]] && command -v rclone >/dev/null 2>&1; then
  if rclone listremotes 2>/dev/null | grep -q '^gdrive:'; then
    log "Upload ke ${RCLONE_REMOTE}/daily/ ..."
    rclone copy "${FILE}" "${RCLONE_REMOTE}/daily/" --checksum
    if [[ "${DAY_OF_WEEK}" == "1" ]]; then
      rclone copy "${FILE}" "${RCLONE_REMOTE}/weekly/" --checksum
    fi
    # Retensi remote (hapus file daily lebih lama dari KEEP_DAILY)
    rclone delete "${RCLONE_REMOTE}/daily/" --min-age "${KEEP_DAILY}d" 2>/dev/null || true
    rclone delete "${RCLONE_REMOTE}/weekly/" --min-age "$((KEEP_WEEKLY * 7))d" 2>/dev/null || true
    log "Upload Google Drive selesai."
  else
    log "SKIP Drive: remote 'gdrive' belum dikonfigurasi. Jalankan: rclone config"
  fi
elif [[ -n "${RCLONE_REMOTE}" ]]; then
  log "SKIP Drive: rclone belum terpasang."
else
  log "SKIP Drive: BMS_RCLONE_REMOTE kosong."
fi

log "Selesai."
