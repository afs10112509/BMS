<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup
        {--force : Jalankan meski jadwal otomatis nonaktif / sudah jalan hari ini}
        {--scheduled : Mode scheduler (hormati pengaturan jadwal Owner)}';

    protected $description = 'Buat backup database PostgreSQL (pg_dump -Fc) ke storage/app/backups/database.';

    public function handle(DatabaseBackupService $backups): int
    {
        $scheduled = (bool) $this->option('scheduled');
        $force = (bool) $this->option('force');

        if ($scheduled && ! $force && ! $backups->shouldRunScheduledNow()) {
            return self::SUCCESS;
        }

        $this->info('Membuat backup database…');

        try {
            $meta = $backups->createBackup();
        } catch (RuntimeException $e) {
            $msg = $e->getMessage() ?: 'Gagal membuat backup database.';
            $this->error($msg);
            if ($scheduled) {
                $backups->markScheduleRun(false, null, $msg);
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $msg = 'Gagal membuat backup database: '.$e->getMessage();
            $this->error($msg);
            if ($scheduled) {
                $backups->markScheduleRun(false, null, $msg);
            }

            return self::FAILURE;
        }

        $this->info('Backup siap: '.$meta['filename'].' ('.$meta['size_label'].')');

        if ($scheduled) {
            $backups->markScheduleRun(true, $meta['filename'], null);
        }

        return self::SUCCESS;
    }
}
