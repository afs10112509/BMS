<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns to products table if missing
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode')->unique()->nullable()->after('sku');
            }
            if (!Schema::hasColumn('products', 'base_unit')) {
                $table->string('base_unit')->default('pcs')->after('barcode');
            }
            if (!Schema::hasColumn('products', 'requires_serial')) {
                $table->boolean('requires_serial')->default(false)->after('base_unit');
            }
        });

        // 1. product_units
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('unit_name'); // e.g. "dus", "pack"
            $table->decimal('conversion_to_base', 15, 4)->default(1);
            $table->timestamps();
        });

        // 2. product_serials (IMEI / Serial Number tracking)
        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number')->unique();
            $table->enum('status', ['in_stock', 'sold', 'in_service', 'transferred'])->default('in_stock');
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        // 3. price_tiers
        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->enum('customer_type', ['umum', 'reseller', 'grosir'])->nullable();
            $table->integer('min_quantity')->nullable();
            $table->decimal('price', 15, 2);
            $table->timestamps();
        });

        // 4. product_stocks (per branch stock level)
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('min_stock_alert', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'branch_id']);
        });

        // 5. stock_batches (FIFO costing)
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('quantity_in', 15, 2)->default(0);
            $table->decimal('quantity_remaining', 15, 2)->default(0);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->index(['product_id', 'branch_id', 'quantity_remaining']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('price_tiers');
        Schema::dropIfExists('product_serials');
        Schema::dropIfExists('product_units');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'requires_serial')) {
                $table->dropColumn('requires_serial');
            }
            if (Schema::hasColumn('products', 'base_unit')) {
                $table->dropColumn('base_unit');
            }
            if (Schema::hasColumn('products', 'barcode')) {
                $table->dropColumn('barcode');
            }
        });
    }
};
