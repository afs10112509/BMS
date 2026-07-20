<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')
                ->constrained('branches')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('type');
            $table->index(['branch_id', 'type']);
        });

        DB::table('categories')->update(['branch_id' => null, 'is_active' => true]);

        // NULL is distinct in unique indexes on both PostgreSQL and SQLite;
        // use partial unique indexes so globals and locals enforce correctly.
        DB::statement('CREATE UNIQUE INDEX categories_local_unique ON categories (branch_id, type, name) WHERE branch_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX categories_global_unique ON categories (type, name) WHERE branch_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS categories_local_unique');
        DB::statement('DROP INDEX IF EXISTS categories_global_unique');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('is_active');
        });
    }
};
