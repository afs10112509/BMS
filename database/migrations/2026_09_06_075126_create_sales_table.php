<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("sales", function (Blueprint $table) {
            $table->id();
            $table->foreignId("branch_id")->constrained()->cascadeOnDelete();
            $table->string("invoice_number")->unique();
            $table->date("sale_date");
            $table->string("customer_name")->nullable();
            $table->string("customer_phone")->nullable();
            $table->decimal("subtotal", 15, 2)->default(0);
            $table->decimal("discount", 15, 2)->default(0);
            $table->decimal("tax", 15, 2)->default(0);
            $table->decimal("total", 15, 2)->default(0);
            $table->enum("payment_method", ["cash", "transfer", "qris", "debit", "credit"]);
            $table->enum("status", ["pending", "completed", "cancelled"])->default("completed");
            $table->foreignId("created_by")->constrained("users");
            $table->text("notes")->nullable();
            $table->timestamps();
            
            $table->index(["branch_id", "sale_date"]);
            $table->index("invoice_number");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("sales");
    }
};
