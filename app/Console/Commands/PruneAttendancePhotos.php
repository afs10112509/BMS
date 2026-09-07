<?php

namespace App\Console\Commands;

use App\Models\EmployeeAttendance;
use App\Services\Attendance\AttendancePhotoStore;
use Illuminate\Console\Command;

class PruneAttendancePhotos extends Command
{
    protected $signature = 'attendance:prune-photos {--months=3 : Retensi foto dalam bulan}';

    protected $description = 'Hapus foto absensi lebih lama dari masa retensi (default 3 bulan).';

    public function handle(AttendancePhotoStore $photos): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months)->startOfDay();
        $count = 0;

        EmployeeAttendance::query()
            ->where(function ($q) {
                $q->whereNotNull('check_in_photo')->orWhereNotNull('check_out_photo');
            })
            ->whereDate('attendance_date', '<', $cutoff->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($photos, &$count) {
                foreach ($rows as $row) {
                    if ($row->check_in_photo) {
                        $photos->delete($row->check_in_photo);
                        $row->check_in_photo = null;
                        $count++;
                    }
                    if ($row->check_out_photo) {
                        $photos->delete($row->check_out_photo);
                        $row->check_out_photo = null;
                        $count++;
                    }
                    $row->save();
                }
            });

        $this->info("Foto absensi dibersihkan: {$count} berkas (lebih lama dari {$months} bulan).");

        return self::SUCCESS;
    }
}
