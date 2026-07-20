<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPengeluaranCommand extends Command
{
    protected $signature = 'bms:import-pengeluaran
        {--file=database/data/pengeluaran_sawai_2026-06.csv : Path relatif ke CSV}
        {--branch=Sawai : Nama cabang tujuan}
        {--replace : Hapus dulu transaksi pengeluaran cabang pada rentang tanggal CSV}
        {--dry-run : Hanya hitung, tidak insert}';

    protected $description = 'Impor transaksi pengeluaran dari CSV ke BMS';

    /** @var array<string, string> */
    private array $categoryAliases = [
        'token listrik' => 'Listrik',
        'uki;' => 'Uki',
        'hasmin' => 'Hasmin',
    ];

    public function handle(): int
    {
        $relative = $this->option('file');
        $path = base_path($relative);

        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $branch = Branch::query()->where('name', $this->option('branch'))->first();
        if (! $branch) {
            $this->error('Cabang tidak ditemukan: '.$this->option('branch'));

            return self::FAILURE;
        }

        $owner = User::query()->where('role', 'owner')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();

        if (! $owner) {
            $this->error('Tidak ada user untuk user_id transaksi.');

            return self::FAILURE;
        }

        $categories = Category::query()
            ->where('type', 'expense')
            ->get()
            ->keyBy(fn (Category $c) => mb_strtolower($c->name));

        $cashAccount = Account::query()->where('code', 'cash')->first();
        if (! $cashAccount) {
            $this->error('Akun Cash tidak ditemukan. Jalankan AccountSeeder terlebih dahulu.');

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error('Gagal membuka CSV.');

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            $this->error('CSV kosong.');

            return self::FAILURE;
        }

        $header = array_map(fn ($h) => trim(mb_strtolower((string) $h)), $header);
        $required = ['transaction_date', 'amount', 'category'];
        foreach ($required as $col) {
            if (! in_array($col, $header, true)) {
                fclose($handle);
                $this->error("Kolom wajib hilang: {$col}");

                return self::FAILURE;
            }
        }

        $rows = [];
        $seen = [];
        $line = 1;
        $skipped = 0;
        $dupInFile = 0;
        $minDate = null;
        $maxDate = null;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $row = array_combine($header, array_pad($data, count($header), null));
            if ($row === false) {
                $this->warn("Baris {$line}: jumlah kolom tidak cocok, dilewati.");
                $skipped++;

                continue;
            }

            $date = trim((string) ($row['transaction_date'] ?? ''));
            $amount = (float) str_replace([',', ' '], '', (string) ($row['amount'] ?? '0'));
            $categoryName = $this->normalizeCategoryName(trim((string) ($row['category'] ?? '')));
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                $description = null;
            }

            if ($date === '' || $amount <= 0 || $categoryName === '') {
                $this->warn("Baris {$line}: data tidak valid, dilewati.");
                $skipped++;

                continue;
            }

            $category = $categories->get(mb_strtolower($categoryName));
            if (! $category) {
                $this->warn("Baris {$line}: kategori '{$categoryName}' tidak ditemukan, dilewati.");
                $skipped++;

                continue;
            }

            $dedupeKey = $date.'|'.number_format($amount, 2, '.', '').'|'.$category->id.'|'.$cashAccount->id.'|'.($description ?? '');
            if (isset($seen[$dedupeKey])) {
                $dupInFile++;

                continue;
            }
            $seen[$dedupeKey] = true;

            $minDate = $minDate === null || $date < $minDate ? $date : $minDate;
            $maxDate = $maxDate === null || $date > $maxDate ? $date : $maxDate;

            $rows[] = [
                'branch_id' => $branch->id,
                'user_id' => $owner->id,
                'category_id' => $category->id,
                'account_id' => $cashAccount->id,
                'amount' => number_format($amount, 2, '.', ''),
                'description' => $description,
                'transaction_date' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        fclose($handle);

        $total = array_sum(array_map(fn ($r) => (float) $r['amount'], $rows));

        $this->info('Cabang: '.$branch->name);
        $this->info('User: '.$owner->name.' (#'.$owner->id.')');
        $this->info('Kategori expense: '.$categories->keys()->map(fn ($k) => $categories[$k]->name)->unique()->implode(', '));
        $this->info("Rentang tanggal: {$minDate} s/d {$maxDate}");
        $this->info('Siap diimpor: '.count($rows)." baris | total: Rp ".number_format($total, 0, ',', '.')." | dilewati: {$skipped} | duplikat di CSV: {$dupInFile}");

        if ($this->option('dry-run')) {
            $this->comment('Dry-run: tidak ada data yang disimpan.');

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warn('Tidak ada baris untuk diimpor.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $branch, $minDate, $maxDate) {
            if ($this->option('replace') && $minDate && $maxDate) {
                $expenseIds = Category::query()->where('type', 'expense')->pluck('id');
                $deleted = Transaction::query()
                    ->where('branch_id', $branch->id)
                    ->whereIn('category_id', $expenseIds)
                    ->whereBetween('transaction_date', [$minDate, $maxDate])
                    ->delete();
                $this->warn("Replace: menghapus {$deleted} transaksi pengeluaran lama pada rentang tersebut.");
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                Transaction::query()->insert($chunk);
            }
        });

        $this->info('Impor selesai: '.count($rows).' transaksi pengeluaran (Akun CASH).');

        return self::SUCCESS;
    }

    private function normalizeCategoryName(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = rtrim($key, ';');

        if (isset($this->categoryAliases[$key])) {
            return $this->categoryAliases[$key];
        }

        if (isset($this->categoryAliases[$key.';'])) {
            return $this->categoryAliases[$key.';'];
        }

        return $name;
    }
}

