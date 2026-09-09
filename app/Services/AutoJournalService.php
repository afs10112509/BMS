<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AutoJournalService
{
    /**
     * Catat Jurnal Umum Otomatis SAK EMKM.
     * Validasi wajib: Total Debit == Total Kredit.
     */
    public function recordJournal(
        int $branchId,
        string $entryDate,
        string $description,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $userId = null
    ): JournalEntry {
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($lines as $line) {
            $totalDebit += $line['debit'] ?? 0;
            $totalCredit += $line['credit'] ?? 0;
        }

        // Standard SAK EMKM Accounting Validation: Debit = Credit
        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new RuntimeException(
                "Jurnal tidak balance (Debit: {$totalDebit}, Kredit: {$totalCredit}). Deskripsi: {$description}"
            );
        }

        $dt = Carbon::parse($entryDate);

        // Cari atau buat periode akuntansi
        $period = AccountingPeriod::firstOrCreate(
            ['branch_id' => $branchId, 'year' => $dt->year, 'month' => $dt->month],
            ['status' => 'open']
        );

        if ($period->status === 'closed') {
            throw new RuntimeException("Periode akuntansi {$dt->format('Y-m')} sudah dikunci.");
        }

        return DB::transaction(function () use ($branchId, $period, $sourceType, $sourceId, $dt, $description, $userId, $lines) {
            $entry = JournalEntry::create([
                'branch_id' => $branchId,
                'accounting_period_id' => $period->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'entry_date' => $dt->toDateString(),
                'description' => $description,
                'is_manual' => false,
                'created_by' => $userId ?? 1,
            ]);

            foreach ($lines as $line) {
                if (($line['debit'] ?? 0) == 0 && ($line['credit'] ?? 0) == 0) {
                    continue;
                }

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Buat jurnal otomatis transaksi penjualan POS
     */
    public function recordSaleJournal(
        int $branchId,
        string $saleDate,
        string $invoiceNumber,
        float $totalAmount,
        float $totalHpp,
        int $cashAccountId,
        int $salesAccountId,
        int $hppAccountId,
        int $inventoryAccountId,
        ?int $saleId = null,
        ?int $userId = null
    ): JournalEntry {
        $lines = [
            // Debit: Kas / Bank / QRIS
            ['account_id' => $cashAccountId, 'debit' => $totalAmount, 'credit' => 0],
            // Credit: Pendapatan Penjualan
            ['account_id' => $salesAccountId, 'debit' => 0, 'credit' => $totalAmount],
        ];

        // Jika ada pencatatan HPP & Persediaan
        if ($totalHpp > 0) {
            // Debit: Beban Pokok Penjualan (HPP)
            $lines[] = ['account_id' => $hppAccountId, 'debit' => $totalHpp, 'credit' => 0];
            // Credit: Persediaan Barang Dagang
            $lines[] = ['account_id' => $inventoryAccountId, 'debit' => 0, 'credit' => $totalHpp];
        }

        return $this->recordJournal(
            branchId: $branchId,
            entryDate: $saleDate,
            description: "Jurnal Otomatis Penjualan #{$invoiceNumber}",
            lines: $lines,
            sourceType: 'sale',
            sourceId: $saleId,
            userId: $userId
        );
    }
}
