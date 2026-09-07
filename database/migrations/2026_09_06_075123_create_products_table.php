<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("products", function (Blueprint $table) {
            $table->id();
            $table->foreignId("branch_id")->constrained()->cascadeOnDelete();
            $table->string("sku")->unique();
            $table->string("name");
            $table->enum("type", ["phone", "accessory", "spare_part", "other"]);
            $table->text("description")->nullable();
            $table->string("brand")->nullable();
            $table->string("model")->nullable();
            $table->decimal("cost_price", 15, 2)->default(0);
            $table->decimal("selling_price", 15, 2)->default(0);
            $table->integer("stock_quantity")->default(0);
            $table->integer("min_stock")->default(5);
            $table->foreignId("supplier_id")->nullable()->constrained()->nullOnDelete();
            $table->boolean("is_active")->default(true);
            $table->timestamps();
            
            $table->index(["branch_id", "type"]);
            $table->index("sku");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("products");
    }
};
