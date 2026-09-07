<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("sale_items", function (Blueprint $table) {
            $table->id();
            $table->foreignId("sale_id")->constrained()->cascadeOnDelete();
            $table->foreignId("product_id")->constrained();
            $table->integer("quantity");
            $table->decimal("unit_price", 15, 2);
            $table->decimal("discount", 15, 2)->default(0);
            $table->decimal("subtotal", 15, 2);
            $table->timestamps();
            
            $table->index("sale_id");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("sale_items");
    }
};
