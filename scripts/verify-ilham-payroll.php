<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;

$names = Employee::query()
    ->where('status', 'active')
    ->whereHas('branch.branchType', fn ($q) => $q->where('allows_service', true))
    ->withoutOwner()
    ->orderBy('name')
    ->pluck('name');

echo 'HAS_ILHAM='.($names->contains('Ilham') ? 'YES' : 'NO').PHP_EOL;
echo 'COUNT='.$names->count().PHP_EOL;
$ilham = Employee::query()->where('name', 'ilike', '%ilham%')->first();
if ($ilham) {
    echo 'POS='.$ilham->position.'|mgmt='.($ilham->isManagement() ? '1' : '0').PHP_EOL;
}
