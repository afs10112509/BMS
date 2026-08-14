# BMS Desktop (Windows)

Aplikasi desktop Windows yang membuka UI BMS dari server  
(sehingga update di server otomatis terlihat di desktop).

## Fitur
- Jendela desktop sendiri (bukan browser biasa)
- Tombol **Muat Ulang** di dalam aplikasi (samping Akun)
- URL bisa diubah lewat `config.json`

## Build installer / exe

```powershell
cd D:\laragon\www\BMS\desktop
python -m pip install -r requirements.txt
powershell -ExecutionPolicy Bypass -File .\build.ps1
```

Hasil:
- `dist\BMS Desktop.exe` — aplikasi portable
- `dist\BMS-Desktop-Setup.exe` — installer (jika Inno Setup terpasang)

## Config

Salin `config.example.json` menjadi `config.json` di folder yang sama dengan `.exe`:

```json
{
  "url": "https://bms.adbr.my.id/app/",
  "title": "BMS — Belawa Management System"
}
```
