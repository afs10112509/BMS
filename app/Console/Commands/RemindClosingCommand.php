<?php

namespace App\Console\Commands;

use App\Services\Closing\ClosingReminderService;
use App\Services\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RemindClosingCommand extends Command
{
    protected $signature = 'closing:remind
        {--date= : Tanggal closing yang diingatkan (Y-m-d). Default: kemarin}
        {--dry-run : Tampilkan daftar & pesan, tanpa kirim ke n8n}';

    protected $description = 'Kirim reminder closing harian ke semua karyawan konter aktif (data D-1).';

    public function handle(ClosingReminderService $reminders, NotificationDispatcher $notifier): int
    {
        $tz = (string) config('app.timezone');
        $dateOption = $this->option('date');

        try {
            $forDate = $dateOption
                ? Carbon::parse((string) $dateOption, $tz)->startOfDay()
                : now($tz)->subDay()->startOfDay();
        } catch (\Throwable) {
            $this->error('Format --date tidak valid. Gunakan Y-m-d, contoh: 2026-08-13');

            return self::FAILURE;
        }

        $bundle = $reminders->build($forDate);
        $recipients = $bundle['recipients'];
        $skipped = $bundle['skipped'];

        $this->info('Reminder closing '.$bundle['date_label'].' ('.$bundle['date'].')');
        $this->info('Akan dikirim: '.count($recipients).' · Dilewati (tanpa HP): '.count($skipped));

        if ($recipients !== []) {
            $this->table(
                ['Karyawan', 'Cabang', 'HP', 'Qty D-1', 'Bulan', 'Status'],
                collect($recipients)->map(fn (array $row) => [
                    $row['name'],
                    $row['branch_name'] ?? '—',
                    $row['phone'],
                    $row['missing'] ? 'belum' : (string) $row['yesterday_qty'],
                    ($row['month_qty'] ?? 0).' / '.($row['target'] ?? 0),
                    ! empty($row['tercapai']) ? 'Tercapai' : 'Belum',
                ])->all(),
            );
        }

        if ($skipped !== []) {
            $this->warn('Dilewati karena nomor HP kosong/tidak valid:');
            foreach ($skipped as $row) {
                $this->line('  - '.($row['name'] ?? '?').' ('.($row['branch_name'] ?? '—').')');
            }
        }

        if ($this->option('dry-run')) {
            foreach ($recipients as $row) {
                $this->newLine();
                $this->line('--- '.$row['name'].' ---');
                $this->line($row['message']);
            }
            $this->newLine();
            $this->comment('Dry-run: tidak ada pesan yang dikirim ke n8n.');

            return self::SUCCESS;
        }

        if ($recipients === []) {
            $this->comment('Tidak ada penerima yang bisa dikirimi (semua tanpa nomor HP, atau tidak ada karyawan konter aktif).');

            return self::SUCCESS;
        }

        $ok = $notifier->notifyClosingReminder($bundle);
        if (! $ok) {
            $this->error('Webhook n8n gagal atau N8N_WEBHOOK_URL belum diisi.');

            return self::FAILURE;
        }

        $this->info('Payload reminder_closing dikirim ke n8n.');

        return self::SUCCESS;
    }
}
