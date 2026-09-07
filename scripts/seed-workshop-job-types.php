<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\WorkshopJobType;

$branches = Branch::query()
    ->with('branchType')
    ->get()
    ->filter(fn (Branch $b) => $b->isWorkshop());

$created = 0;
$skipped = 0;

foreach ($branches as $branch) {
    foreach (WorkshopJobType::DEFAULT_NAMES as $i => $name) {
        $exists = WorkshopJobType::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            $skipped++;
            continue;
        }

        WorkshopJobType::query()->withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'name' => $name,
            'default_amount' => null,
            'status' => WorkshopJobType::STATUS_ACTIVE,
            'sort_order' => $i + 1,
            'created_by' => null,
        ]);
        $created++;
        echo "CREATED={$branch->id}|{$branch->name}|{$name}\n";
    }
}

echo "DONE created={$created} skipped={$skipped}\n";
