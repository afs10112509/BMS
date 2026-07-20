<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeDailyClosing;
use App\Models\EmployeeMonthlyTarget;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportClosingsJuniCommand extends Command
{
    protected $signature = 'bms:import-closings-juni
        {--year=2026 : Tahun}
        {--month=6 : Bulan}
        {--daily=database/data/closings_juni_2026.csv : CSV closing harian}
        {--targets=database/data/targets_juni_2026.csv : CSV target bulanan}
        {--replace : Hapus dulu data closing/target periode ini untuk karyawan terkait}
        {--dry-run : Hanya hitung, tidak simpan}';

    protected $description = 'Impor target & closingan non-promotor Juni ke BMS';

    /** @var array<string, string> nama pencarian karyawan */
    private array $employeeNames = [
        'ilham' => 'Ilham',
        'abel' => 'Abel',
        'ulfa' => 'Ulfa',
        'siti' => 'Siti',
        'hasmin' => 'Hasmin',
        'roni' => 'Roni',
        'zulkifli' => 'Zulkifli',
        'sisy' => 'Sisy',
        'awaluddin' => 'Awaluddin',
        'rafli' => 'Rafli',
        'bahar' => 'Bahar',
        'wirda' => 'Wirda',
    ];

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $month = (int) $this->option('month');
        $dailyPath = base_path($this->option('daily'));
        $targetPath = base_path($this->option('targets'));
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');

        if (! is_file($dailyPath) || ! is_file($targetPath)) {
            $this->error('File CSV tidak ditemukan.');

            return self::FAILURE;
        }

        $employees = $this->resolveEmployees();
        if ($employees === null) {
            return self::FAILURE;
        }

        $ownerId = User::query()->where('role', 'owner')->value('id');

        $targets = $this->readCsv($targetPath);
        $dailies = $this->readCsv($dailyPath);

        $targetRows = 0;
        $dailyRows = 0;
        $qtySum = 0;

        foreach ($targets as $row) {
            $key = strtolower(trim((string) ($row['employee_key'] ?? '')));
            if ($key === '' || ! isset($employees[$key])) {
                continue;
            }
            $targetRows++;
        }

        foreach ($dailies as $row) {
            $key = strtolower(trim((string) ($row['employee_key'] ?? '')));
            $day = (int) ($row['day'] ?? 0);
            $qty = (int) ($row['qty'] ?? 0);
            if ($key === '' || ! isset($employees[$key]) || $day < 1 || $day > 31 || $qty <= 0) {
                continue;
            }
            $dailyRows++;
            $qtySum += $qty;
        }

        $this->info("Karyawan terpetakan: ".count($employees));
        $this->info("Target rows: {$targetRows}");
        $this->info("Daily rows: {$dailyRows}, qty sum: {$qtySum}");

        if ($dryRun) {
            $this->warn('Dry-run: tidak ada data yang disimpan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($employees, $targets, $dailies, $year, $month, $ownerId, $replace) {
            $employeeIds = collect($employees)->pluck('id')->all();

            if ($replace) {
                EmployeeDailyClosing::query()
                    ->whereIn('employee_id', $employeeIds)
                    ->whereYear('closing_date', $year)
                    ->whereMonth('closing_date', $month)
                    ->delete();

                EmployeeMonthlyTarget::query()
                    ->whereIn('employee_id', $employeeIds)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->delete();
            }

            foreach ($targets as $row) {
                $key = strtolower(trim((string) ($row['employee_key'] ?? '')));
                if ($key === '' || ! isset($employees[$key])) {
                    continue;
                }

                EmployeeMonthlyTarget::query()->updateOrCreate(
                    [
                        'employee_id' => $employees[$key]->id,
                        'year' => $year,
                        'month' => $month,
                    ],
                    [
                        'target' => (int) ($row['target'] ?? 0),
                        'set_by' => $ownerId,
                    ]
                );
            }

            foreach ($dailies as $row) {
                $key = strtolower(trim((string) ($row['employee_key'] ?? '')));
                $day = (int) ($row['day'] ?? 0);
                $qty = (int) ($row['qty'] ?? 0);
                if ($key === '' || ! isset($employees[$key]) || $day < 1 || $day > 31 || $qty <= 0) {
                    continue;
                }

                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

                EmployeeDailyClosing::query()->updateOrCreate(
                    [
                        'employee_id' => $employees[$key]->id,
                        'closing_date' => $date,
                    ],
                    [
                        'qty' => $qty,
                        'input_by' => $ownerId,
                    ]
                );
            }
        });

        $this->info('Impor selesai.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, Employee>|null
     */
    private function resolveEmployees(): ?array
    {
        $map = [];

        foreach ($this->employeeNames as $key => $name) {
            $candidates = Employee::query()
                ->where('name', 'ilike', '%'.$name.'%')
                ->get();

            $employee = $candidates->first(fn (Employee $e) => strcasecmp($e->name, $name) === 0)
                ?? $candidates->first(fn (Employee $e) => str_starts_with(mb_strtolower($e->name), mb_strtolower($name)))
                ?? $candidates->first();

            if (! $employee) {
                $this->error("Karyawan tidak ditemukan untuk key={$key} name={$name}");

                return null;
            }

            $this->line("  {$key} → #{$employee->id} {$employee->name}");
            $map[$key] = $employee;
        }

        return $map;
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }

        $header = null;
        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim((string) $h)), $data);
                continue;
            }
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }
}
