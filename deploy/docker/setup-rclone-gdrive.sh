#!/usr/bin/env bash
# Setup sekali: install rclone (jika belum) + panduan konfigurasi Google Drive.
set -euo pipefail

echo "==> Cek rclone..."
if ! command -v rclone >/dev/null 2>&1; then
  echo "Mengunduh rclone (binary user)..."
  TMP="$(mktemp -d)"
  cd "${TMP}"
  curl -fsSL -o rclone.zip "https://downloads.rclone.org/rclone-current-linux-amd64.zip"
  unzip -q rclone.zip
  mkdir -p "${HOME}/.local/bin"
  install -m 755 rclone-*/rclone "${HOME}/.local/bin/rclone"
  cd /
  rm -rf "${TMP}"
  export PATH="${HOME}/.local/bin:${PATH}"
  echo "rclone terpasang di ${HOME}/.local/bin/rclone"
else
  echo "rclone sudah ada: $(command -v rclone)"
fi

echo
echo "=============================================="
echo "  Konfigurasi Google Drive (sekali saja)"
echo "=============================================="
echo
echo "Jalankan di terminal interaktif (SSH):"
echo
echo "  export PATH=\"\$HOME/.local/bin:\$PATH\""
echo "  rclone config"
echo
echo "Langkah di menu rclone:"
echo "  n) New remote"
echo "  name> gdrive"
echo "  Storage> Google Drive  (ketik angka yang sesuai, biasanya 18)"
echo "  client_id>     (kosongkan Enter)"
echo "  client_secret> (kosongkan Enter)"
echo "  scope> 1  (Full access)"
echo "  root_folder_id> (kosongkan)"
echo "  service_account> (kosongkan)"
echo "  Edit advanced? n"
echo "  Use auto config? n   <-- penting di server headless"
echo "  Lalu buka link yang muncul di browser PC Anda, login Google,"
echo "  copy kode verifikasi, tempel di SSH."
echo "  Configure as team drive? n"
echo "  y) Yes this is OK"
echo "  q) Quit"
echo
echo "Uji:"
echo "  rclone lsd gdrive:"
echo "  rclone mkdir gdrive:BMS-Backups"
echo
echo "Setelah itu backup otomatis akan upload ke gdrive:BMS-Backups"
echo "=============================================="
