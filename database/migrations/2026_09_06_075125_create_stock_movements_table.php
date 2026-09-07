<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("stock_movements", function (Blueprint $table) {
            $table->id();
            $table->foreignId("product_id")->constrained()->cascadeOnDelete();
            $table->foreignId("branch_id")->constrained()->cascadeOnDelete();
            $table->enum("type", ["in", "out", "adjustment"]);
            $table->integer("quantity");
            $table->integer("stock_before");
            $table->integer("stock_after");
            $table->string("reference_type")->nullable();
            $table->unsignedBigInteger("reference_id")->nullable();
            $table->text("notes")->nullable();
            $table->foreignId("created_by")->constrained("users");
            $table->timestamps();
            
            $table->index(["product_id", "created_at"]);
            $table->index(["branch_id", "type"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("stock_movements");
    }
};
