<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. accounting_periods
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->integer('month');
            $table->integer('year');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'year', 'month']);
        });

        // 2. journal_entries
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('accounting_period_id')->nullable()->constrained('accounting_periods')->nullOnDelete();
            $table->string('source_type')->nullable(); // polymorphic: transaction, service, pulsa, brilink, etc.
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('entry_date');
            $table->string('description');
            $table->boolean('is_manual')->default(false);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });

        // 3. journal_lines
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();

            $table->index('journal_entry_id');
            $table->index('account_id');
        });

        // 4. employee_points
        Schema::create('employee_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('source_type'); // polymorphic: transaction, service, etc.
            $table->unsignedBigInteger('source_id');
            $table->integer('points'); // can be negative for redemption
            $table->enum('type', ['earned', 'redeemed'])->default('earned');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_points');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
    }
};
