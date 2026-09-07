<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'app_timezone=' . config('app.timezone') . PHP_EOL;
echo 'laravel_now=' . now()->toDateTimeString() . PHP_EOL;
echo 'laravel_tz=' . now()->timezoneName . PHP_EOL;
echo 'php_date=' . date('Y-m-d H:i:s') . PHP_EOL;
