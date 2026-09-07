<?php

namespace App\Services\ProfitShare;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ProfitShareLine;
use Illuminate\Support\Collection;

class ProfitShareDefaults
{
    /** @return list<string> */
    public static function konterIncomeNames(): array
    {
        return [
            'Penjualan HP',
            'Penjualan ACC',
            'Penjualan Pulsa',
            'Jasa Brilink',
        ];
    }

    /** @return list<string> */
    public static function konterExpenseNames(): array
    {
        return [
            'Gaji karyawan',
            'Insentif ACC',
            'Insentif HP',
            'Sewa toko',
            'Listrik',
            'Wifi',
            'Operasional',
            'Dapur',
            'Susut stok',
        ];
    }

    /**
     * Default PIC: karyawan jabatan PIC di cabang, lalu cocok nama lama, lalu kosong.
     *
     * @param  Collection<int, Employee>|null  $employees
     * @return array{pic_employee_id: int|null, pic_name: string, pic_share_pct: float}
     */
    public static function defaultPicForBranch(Branch $branch, ?Collection $employees = null): array
    {
        $key = mb_strtolower(trim((string) $branch->name));
        $legacy = match (true) {
            str_contains($key, 'sawai') => ['pic_name' => 'Hasmin', 'pic_share_pct' => 50.0],
            str_contains($key, 'lukulamo') => ['pic_name' => 'Awal', 'pic_share_pct' => 50.0],
            str_contains($key, 'bengkel') => ['pic_name' => 'Aswar', 'pic_share_pct' => 50.0],
            str_contains($key, 'waebulen') => ['pic_name' => '', 'pic_share_pct' => 50.0],
            default => ['pic_name' => '', 'pic_share_pct' => 50.0],
        };

        $pool = $employees?->values() ?? collect();
        $sameBranch = $pool->where('branch_id', $branch->id)->values();
        $picked = null;
        if ($legacy['pic_name'] !== '') {
            $needle = mb_strtolower($legacy['pic_name']);
            $match = function (Employee $e) use ($needle) {
                return mb_strtolower(trim((string) $e->name)) === $needle;
            };
            $picked = $sameBranch->first($match) ?? $pool->first($match);
        }
        $picked ??= $sameBranch->first();

        return [
            'pic_employee_id' => $picked?->id,
            'pic_name' => $picked?->name ?: $legacy['pic_name'],
            'pic_share_pct' => $legacy['pic_share_pct'],
        ];
    }

    /**
     * Template pos awal: konter punya daftar; bengkel kosong (pos bebas).
     *
     * @return list<array{type: string, name: string, amount: float, sort_order: int}>
     */
    public static function templateLines(Branch $branch): array
    {
        if ($branch->isWorkshop()) {
            return [];
        }

        $lines = [];
        $order = 0;
        foreach (self::konterIncomeNames() as $name) {
            $lines[] = [
                'type' => ProfitShareLine::TYPE_INCOME,
                'name' => $name,
                'amount' => 0.0,
                'sort_order' => $order++,
            ];
        }
        foreach (self::konterExpenseNames() as $name) {
            $lines[] = [
                'type' => ProfitShareLine::TYPE_EXPENSE,
                'name' => $name,
                'amount' => 0.0,
                'sort_order' => $order++,
            ];
        }

        return $lines;
    }
}
