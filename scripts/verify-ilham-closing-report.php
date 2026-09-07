<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Services\ReportBuilder;

$e = Employee::query()->where('name', 'ilike', '%ilham%')->first();
echo 'ILHAM='.($e ? $e->id.'|'.$e->name.'|'.$e->position.'|mgmt='.($e->isManagement() ? '1' : '0') : 'NULL').PHP_EOL;

$data = app(ReportBuilder::class)->closing([
    'date_from' => '2026-07-01',
    'date_to' => '2026-07-31',
    'branch_id' => null,
]);

$names = collect($data['rows'])->pluck('nama');
echo 'HAS_ILHAM='.($names->contains(fn ($n) => str_contains(mb_strtolower($n), 'ilham')) ? 'YES' : 'NO').PHP_EOL;
echo 'COUNT='.count($data['rows']).PHP_EOL;
foreach ($data['rows'] as $r) {
    if (str_contains(mb_strtolower($r['nama']), 'ilham')) {
        echo 'ROW='.json_encode($r, JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
