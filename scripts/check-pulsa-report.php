<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\ReportController;
use App\Services\ReportBuilder;
use Illuminate\Support\Facades\Route;

echo "=== ReportController::TYPES ===\n";
echo implode("\n", ReportController::TYPES) . "\n";
echo "has keuntungan-pulsa: " . (in_array('keuntungan-pulsa', ReportController::TYPES, true) ? 'YES' : 'NO') . "\n\n";

echo "=== ReportBuilder::keuntunganPulsa ===\n";
echo method_exists(ReportBuilder::class, 'keuntunganPulsa') ? "YES\n" : "NO\n";

echo "\n=== matching routes ===\n";
foreach (Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (! str_contains($uri, 'reports')) {
        continue;
    }
    echo $route->methods()[0] . ' ' . $uri . ' -> ' . $route->getActionName() . "\n";
    $wheres = $route->wheres;
    if ($wheres) {
        echo '  where: ' . json_encode($wheres) . "\n";
    }
}
