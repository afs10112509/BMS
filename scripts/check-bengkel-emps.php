<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\WorkshopJob;

$rows = Employee::query()
    ->where('branch_id', 3)
    ->orderBy('name')
    ->get(['id', 'name', 'status']);

foreach ($rows as $r) {
    echo "{$r->id}|{$r->name}|{$r->status}\n";
}

$jul = WorkshopJob::withoutGlobalScopes()
    ->where('branch_id', 3)
    ->whereBetween('job_date', ['2026-07-01', '2026-07-31'])
    ->count();
echo "JOBS_JUL={$jul}\n";
