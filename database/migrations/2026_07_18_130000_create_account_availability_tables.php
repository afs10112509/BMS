<?php

use App\Models\Account;
use App\Models\BranchType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_branch_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('branch_type_id')->constrained('branch_types')->cascadeOnDelete();
            $table->unique(['account_id', 'branch_type_id']);
        });

        Schema::create('account_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unique(['account_id', 'branch_id']);
        });

        $accountIds = Account::query()->where('is_active', true)->pluck('id');
        $konterId = BranchType::query()->where('code', 'konter')->value('id');
        $bengkelId = BranchType::query()->where('code', 'bengkel')->value('id');

        if ($konterId) {
            foreach ($accountIds as $accountId) {
                DB::table('account_branch_type')->insert([
                    'account_id' => $accountId,
                    'branch_type_id' => $konterId,
                ]);
            }
        }

        if ($bengkelId) {
            $bengkelCodes = ['cash', 'mandiri'];
            $bengkelAccountIds = Account::query()
                ->whereIn('code', $bengkelCodes)
                ->pluck('id');

            // Fallback: jika Cash/Mandiri belum ada, pakai semua akun aktif
            if ($bengkelAccountIds->isEmpty()) {
                $bengkelAccountIds = $accountIds;
            }

            foreach ($bengkelAccountIds as $accountId) {
                DB::table('account_branch_type')->insert([
                    'account_id' => $accountId,
                    'branch_type_id' => $bengkelId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_branch');
        Schema::dropIfExists('account_branch_type');
    }
};
