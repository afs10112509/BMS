<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulsa_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('status', 20)->default('active'); // active|inactive
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        DB::statement('CREATE UNIQUE INDEX pulsa_providers_branch_name_unique ON pulsa_providers (branch_id, LOWER(name))');

        Schema::create('pulsa_daily_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('sheet_date');
            $table->decimal('cash_on_hand', 15, 2)->default(0); // uang pulsa fisik
            $table->decimal('total_used_balance', 15, 2)->default(0); // saldo terpotong
            $table->decimal('total_expense', 15, 2)->default(0);
            $table->decimal('total_cash', 15, 2)->default(0); // uang + pengeluaran
            $table->decimal('profit', 15, 2)->default(0); // keuntungan
            $table->string('note')->nullable();
            $table->foreignId('input_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'sheet_date']);
            $table->index(['sheet_date']);
        });

        Schema::create('pulsa_daily_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pulsa_daily_sheet_id')->constrained('pulsa_daily_sheets')->cascadeOnDelete();
            $table->foreignId('pulsa_provider_id')->nullable()->constrained('pulsa_providers')->nullOnDelete();
            $table->string('provider_name', 100); // snapshot
            $table->decimal('opening_balance', 15, 2)->default(0); // saldo kemarin
            $table->decimal('topup_amount', 15, 2)->default(0); // tambah saldo
            $table->decimal('closing_balance', 15, 2)->default(0); // saldo sekarang
            $table->decimal('used_amount', 15, 2)->default(0); // terpakai
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['pulsa_daily_sheet_id']);
        });

        Schema::create('pulsa_daily_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pulsa_daily_sheet_id')->constrained('pulsa_daily_sheets')->cascadeOnDelete();
            $table->string('name', 150);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['pulsa_daily_sheet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulsa_daily_expenses');
        Schema::dropIfExists('pulsa_daily_balances');
        Schema::dropIfExists('pulsa_daily_sheets');
        Schema::dropIfExists('pulsa_providers');
    }
};
