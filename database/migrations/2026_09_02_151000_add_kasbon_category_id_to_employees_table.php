<?php

use App\Models\Category;
use App\Models\Employee;
use App\Services\Payroll\KasbonCategoryLinker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('kasbon_category_id')
                ->nullable()
                ->after('notes')
                ->constrained('categories')
                ->nullOnDelete();
            $table->unique('kasbon_category_id');
        });

        $cats = Category::query()
            ->where('type', 'expense')
            ->whereRaw("LOWER(name) LIKE 'kasbon%'")
            ->orderBy('id')
            ->get(['id', 'name', 'type']);

        $taken = [];
        $employees = Employee::query()->orderBy('id')->get(['id', 'name']);
        foreach ($employees as $employee) {
            $id = KasbonCategoryLinker::suggest($employee->name, $cats, $taken);
            if ($id === null) {
                continue;
            }
            $employee->kasbon_category_id = $id;
            $employee->save();
            $taken[] = $id;
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['kasbon_category_id']);
            $table->dropConstrainedForeignId('kasbon_category_id');
        });
    }
};
