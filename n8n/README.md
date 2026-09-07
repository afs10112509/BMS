# Workflow n8n — BMS Reminder Closing

## Masalah umum kenapa “belum jalan”

1. `N8N_WEBHOOK_URL` di BMS kosong
2. Workflow n8n belum di-**import** / belum **Active**
3. Credential WAHA di node belum dipilih
4. Perintah `closing:remind` belum ada di server (file belum di-deploy)
5. Scheduler `bms-scheduler` harus hidup (`php artisan schedule:work`)

## Setup

### 1) Import workflow

1. Buka https://n8n.adbr.my.id
2. **Workflows → Import from File**
3. Pilih `n8n/BMS-Reminder-Closing.json`
4. Pada node **WAHA SendText**, pilih credential WAHA yang sudah ada
5. Sesuaikan `session` jika bukan `default`
6. Klik **Active** (toggle ON)

Webhook produksi:

```text
https://n8n.adbr.my.id/webhook/bms-notifications
```

### 2) BMS `.env`

```env
N8N_WEBHOOK_URL=https://n8n.adbr.my.id/webhook/bms-notifications
```

Lalu:

```bash
docker compose exec bms-php php artisan config:clear
```

### 3) Tes dari BMS

```bash
# Lihat penerima + isi pesan (tidak kirim)
docker compose exec bms-php php artisan closing:remind --dry-run

# Kirim ke n8n → WAHA
docker compose exec bms-php php artisan closing:remind
```

Jadwal otomatis diatur Owner di BMS: **Sistem → Pengingat** (jam + jabatan penerima). Scheduler `bms-scheduler` mengecek tiap menit.

Penerima: karyawan cabang konter aktif yang jabatannya dipilih Owner (PIC, Kasir, Promotor, Fronliner, Teknisi — boleh lebih dari satu). Karyawan bengkel, nonaktif, Owner, jabatan di luar pilihan, dan nomor HP kosong dilewati.

## Payload yang dikirim Laravel

```json
{
  "event": "reminder_closing",
  "data": {
    "date": "2026-08-13",
    "date_label": "...",
    "recipients": [
      {
        "name": "Siti",
        "phone": "62812...",
        "message": "*Reminder Closing — BMS*\n..."
      }
    ],
    "skipped": []
  }
}
```
