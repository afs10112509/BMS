<?php

namespace App\Console\Commands;

use App\Services\Closing\ClosingReminderService;
use App\Services\NotificationDispatcher;
use App\Services\ReminderSettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RemindClosingCommand extends Command
{
    protected $signature = 'closing:remind
        {--date= : Tanggal closing yang diingatkan (Y-m-d). Default: kemarin}
        {--dry-run : Tampilkan daftar & pesan, tanpa kirim ke n8n}
        {--scheduled : Mode scheduler (hormati jam & jabatan yang diatur Owner)}';

    protected $description = 'Kirim reminder closing harian ke karyawan konter sesuai jabatan yang dipilih Owner.';

    public function handle(
        ClosingReminderService $reminders,
        NotificationDispatcher $notifier,
        ReminderSettingsService $settings,
    ): int {
        $scheduled = (bool) $this->option('scheduled');
        $dryRun = (bool) $this->option('dry-run');

        if ($scheduled && ! $dryRun && ! $settings->shouldRunNow(ReminderSettingsService::TYPE_CLOSING)) {
            return self::SUCCESS;
        }

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

        $closing = $settings->get(ReminderSettingsService::TYPE_CLOSING);
        $bundle = $reminders->build($forDate, $closing['positions']);
        $recipients = $bundle['recipients'];
        $skipped = $bundle['skipped'];
        $positionLabels = $closing['position_labels'] !== []
            ? implode(', ', $closing['position_labels'])
            : '(tidak ada jabatan)';

        $this->info('Reminder closing '.$bundle['date_label'].' ('.$bundle['date'].')');
        $skippedInactive = collect($skipped)->where('reason', 'inactive')->all();
        $skippedNoPhone = collect($skipped)->where('reason', 'no_phone')->all();
        $skippedNotKonter = collect($skipped)->where('reason', 'not_konter')->all();
        $skippedPosition = collect($skipped)->where('reason', 'position')->all();

        $this->info('Penerima: karyawan cabang konter aktif, jabatan: '.$positionLabels.'.');
        $this->info('Akan dikirim: '.count($recipients)
            .' · Dilewati tanpa HP: '.count($skippedNoPhone)
            .' · Dilewati jabatan: '.count($skippedPosition)
            .' · Dilewati nonaktif: '.count($skippedInactive)
            .' · Dilewati bukan konter: '.count($skippedNotKonter));

        if ($recipients !== []) {
            $this->table(
                ['Karyawan', 'Jabatan', 'Cabang', 'HP', 'Qty D-1', 'Bulan', 'Status'],
                collect($recipients)->map(fn (array $row) => [
                    $row['name'],
                    $row['position_label'] ?: '—',
                    $row['branch_name'] ?? '—',
                    $row['phone'],
                    $row['missing'] ? 'belum' : (string) $row['yesterday_qty'],
                    ($row['month_qty'] ?? 0).' / '.($row['target'] ?? 0),
                    ! empty($row['tercapai']) ? 'Tercapai' : 'Belum',
                ])->all(),
            );
        }

        if ($skippedNoPhone !== []) {
            $this->warn('Dilewati karena nomor HP kosong/tidak valid:');
            foreach ($skippedNoPhone as $row) {
                $this->line('  - '.($row['name'] ?? '?').' ('.($row['branch_name'] ?? '—').')');
            }
        }
        if ($skippedPosition !== []) {
            $this->warn('Dilewati karena jabatan tidak termasuk pilihan Owner:');
            foreach ($skippedPosition as $row) {
                $this->line('  - '.($row['name'] ?? '?').' · '.($row['position_label'] ?: 'tanpa jabatan').' ('.($row['branch_name'] ?? '—').')');
            }
        }
        if ($skippedInactive !== []) {
            $this->warn('Dilewati karena karyawan nonaktif:');
            foreach ($skippedInactive as $row) {
                $this->line('  - '.($row['name'] ?? '?').' ('.($row['branch_name'] ?? '—').')');
            }
        }
        if ($skippedNotKonter !== []) {
            $this->warn('Dilewati karena bukan cabang konter:');
            foreach ($skippedNotKonter as $row) {
                $this->line('  - '.($row['name'] ?? '?').' ('.($row['branch_name'] ?? '—').')');
            }
        }

        if ($dryRun) {
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
            $this->comment('Tidak ada penerima yang bisa dikirimi (jabatan tidak cocok, tanpa nomor HP, atau tidak ada karyawan konter aktif).');
            if ($scheduled) {
                $settings->markRun(ReminderSettingsService::TYPE_CLOSING, true, 0, null);
            }

            return self::SUCCESS;
        }

        $ok = $notifier->notifyClosingReminder($bundle);
        if (! $ok) {
            $msg = 'Webhook n8n gagal atau N8N_WEBHOOK_URL belum diisi.';
            $this->error($msg);
            if ($scheduled) {
                $settings->markRun(ReminderSettingsService::TYPE_CLOSING, false, 0, $msg);
            }

            return self::FAILURE;
        }

        if ($scheduled) {
            $settings->markRun(ReminderSettingsService::TYPE_CLOSING, true, count($recipients), null);
        }

        $this->info('Pengingat closing diantrikan ke '.count($recipients).' penerima (jeda ±25 detik antar pesan).');

        return self::SUCCESS;
    }
}
