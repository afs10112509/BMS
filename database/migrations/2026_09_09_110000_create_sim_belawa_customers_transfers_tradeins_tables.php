<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. customers
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->enum('customer_type', ['umum', 'reseller', 'grosir'])->default('umum');
            $table->boolean('whatsapp_opt_in')->default(false);
            $table->timestamps();

            $table->index('phone');
        });

        // 2. customer_points
        Schema::create('customer_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('source_type'); // polymorphic: transaction, service, etc.
            $table->unsignedBigInteger('source_id');
            $table->integer('points'); // can be negative for redemption
            $table->enum('type', ['earned', 'redeemed', 'expired'])->default('earned');
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        // 3. customer_messages
        Schema::create('customer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('channel', ['whatsapp', 'sms', 'push'])->default('whatsapp');
            $table->enum('message_type', ['promo', 'service_status', 'point_info', 'jatuh_tempo']);
            $table->text('content');
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        // 4. stock_transfers (Inter-branch stock transfer request & audit)
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->enum('status', ['diajukan', 'dikirim', 'diterima', 'ditolak'])->default('diajukan');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_branch_id', 'to_branch_id', 'status']);
        });

        // 5. stock_transfer_items
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_serial_id')->nullable()->constrained('product_serials')->nullOnDelete();
            $table->decimal('quantity', 15, 2)->default(1);
            $table->timestamps();
        });

        // 6. trade_ins (Tukar Tambah)
        Schema::create('trade_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('traded_product_name');
            $table->string('traded_serial_number')->nullable();
            $table->decimal('appraised_value', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_ins');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('customer_messages');
        Schema::dropIfExists('customer_points');
        Schema::dropIfExists('customers');
    }
};
