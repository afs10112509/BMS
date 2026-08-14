<?php

namespace App\Services\ProfitShare;

use App\Models\Branch;
use App\Models\ProfitShareLine;

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
     * Default PIC per nama cabang (tahap 1).
     *
     * @return array{pic_name: string, pic_share_pct: float}
     */
    public static function defaultPicForBranch(Branch $branch): array
    {
        $key = mb_strtolower(trim((string) $branch->name));

        return match (true) {
            str_contains($key, 'sawai') => ['pic_name' => 'Hasmin', 'pic_share_pct' => 50.0],
            str_contains($key, 'lukulamo') => ['pic_name' => 'Awal', 'pic_share_pct' => 50.0],
            str_contains($key, 'bengkel') => ['pic_name' => 'Aswar', 'pic_share_pct' => 50.0],
            str_contains($key, 'waebulen') => ['pic_name' => '', 'pic_share_pct' => 50.0],
            default => ['pic_name' => '', 'pic_share_pct' => 50.0],
        };
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
