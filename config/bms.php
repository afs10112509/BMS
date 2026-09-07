<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup database (Owner)
    |--------------------------------------------------------------------------
    |
    | Dump memakai pg_dump (-Fc). Di server Docker, set BMS_BACKUP_DOCKER_CONTAINER
    | ke nama container Postgres (contoh: postgres) agar dump dijalankan via docker exec.
    |
    */
    'backup' => [
        'directory' => storage_path('app/backups/database'),
        'keep' => (int) env('BMS_BACKUP_KEEP', 10),
        'timeout' => (int) env('BMS_BACKUP_TIMEOUT', 300),
        'pg_dump' => env('BMS_PG_DUMP', 'pg_dump'),
        'pg_restore' => env('BMS_PG_RESTORE', 'pg_restore'),
        'docker_container' => env('BMS_BACKUP_DOCKER_CONTAINER'),
        'docker_bin' => env('BMS_DOCKER_BIN', 'docker'),
        'restore_confirm_phrase' => 'PULIHKAN',
        'upload_max_kb' => (int) env('BMS_BACKUP_UPLOAD_MAX_KB', 20480),
        // Jadwal otomatis (bisa diubah Owner lewat UI; nilai env = default awal)
        'schedule_enabled' => filter_var(env('BMS_BACKUP_SCHEDULE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'schedule_time' => env('BMS_BACKUP_SCHEDULE_TIME', '02:00'),
        'schedule_file' => storage_path('app/backups/schedule.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pengingat WhatsApp (Owner)
    |--------------------------------------------------------------------------
    |
    | Jadwal & penerima diatur di BMS (Sistem → Pengingat). Scheduler Laravel
    | mengecek tiap menit; n8n hanya mengirim ke WAHA.
    |
    */
    'reminders' => [
        'file' => storage_path('app/reminders/settings.json'),
        'types' => [
            'closing' => [
                'label' => 'Closing harian',
                'description' => 'Pengingat closing kemarin ke karyawan cabang konter aktif, sesuai jabatan yang dipilih.',
                'default_enabled' => true,
                'default_time' => '08:00',
                'default_positions' => ['pic', 'kasir', 'promotor', 'fronliner', 'teknisi'],
                'scope' => 'konter',
            ],
        ],
        // Jeda antar pesan WhatsApp (detik). Wajib — kirim sekaligus membuat nomor mudah diblokir.
        'send_delay_seconds' => (int) env('BMS_REMINDER_SEND_DELAY', 25),
        'send_delay_jitter' => (int) env('BMS_REMINDER_SEND_JITTER', 8),
    ],
];
