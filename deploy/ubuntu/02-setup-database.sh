#!/usr/bin/env bash
# Buat database & user PostgreSQL untuk BMS.
# Usage: sudo bash deploy/ubuntu/02-setup-database.sh

set -euo pipefail

DB_NAME="${BMS_DB_NAME:-db_bms}"
DB_USER="${BMS_DB_USER:-bms_app}"
DB_PASS="${BMS_DB_PASS:-}"

if [[ -z "${DB_PASS}" ]]; then
  read -rsp "Password PostgreSQL untuk user ${DB_USER}: " DB_PASS
  echo
  read -rsp "Konfirmasi password: " DB_PASS2
  echo
  if [[ "${DB_PASS}" != "${DB_PASS2}" ]]; then
    echo "Password tidak cocok." >&2
    exit 1
  fi
fi

echo "==> Buat role & database..."
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
    CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}';
  ELSE
    ALTER ROLE ${DB_USER} WITH PASSWORD '${DB_PASS}';
  END IF;
END
\$\$;

SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec

GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};
SQL

echo "==> Database siap: ${DB_NAME} (user: ${DB_USER})"
echo "    Simpan password di /var/www/bms/.env"
