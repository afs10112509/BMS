<?php

/**
 * Cek route API vs method controller yang benar-benar ada.
 * Jalankan: php scripts/check-prod-methods.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$routesFile = file_get_contents(__DIR__.'/../routes/api.php');
preg_match_all(
    "/\\\\?([A-Za-z0-9_]+Controller)::class,\s*'([A-Za-z0-9_]+)'/",
    $routesFile,
    $m,
    PREG_SET_ORDER
);

$missing = [];
$ok = [];
foreach ($m as $row) {
    $short = $row[1];
    $method = $row[2];
    $class = 'App\\Http\\Controllers\\Api\\'.$short;
    $label = $short.'::'.$method;
    if (! class_exists($class)) {
        $missing[] = "CLASS $class";
        continue;
    }
    if (! method_exists($class, $method)) {
        $missing[] = $label;
        continue;
    }
    $ok[] = $label;
}

$extra = [
    'App\\Models\\User::isEmployee',
    'App\\Models\\User::isOwner',
    'App\\Models\\User::isAdmin',
    'App\\Models\\User::isPicEmployee',
    'App\\Models\\Employee::scopeWithoutOwner',
    'App\\Models\\Employee::kasbonCategory',
    'App\\Models\\Employee::hasPosition',
    'App\\Services\\AuditLogger::log',
    'App\\Services\\AuditLogger::logTable',
    'App\\Services\\NotificationDispatcher::notifyClosingReminder',
    'App\\Http\\Middleware\\BlockEmployeeStaffApi',
];

foreach ($extra as $item) {
    if (str_contains($item, 'Middleware\\')) {
        if (! class_exists($item)) {
            $missing[] = $item;
        } else {
            $ok[] = $item;
        }
        continue;
    }
    [$class, $method] = explode('::', $item);
    if (! class_exists($class)) {
        $missing[] = "CLASS $class";
        continue;
    }
    if (! method_exists($class, $method)) {
        $missing[] = $item;
        continue;
    }
    $ok[] = $item;
}

$alias = app()->bound('router') ? app('router')->getMiddleware() : [];
if (! isset($alias['block.employee']) && ! in_array(\App\Http\Middleware\BlockEmployeeStaffApi::class, $alias, true)) {
    // bootstrap alias tidak selalu di getMiddleware() sebelum request; cek file.
}

echo 'OK: '.count($ok)."\n";
echo 'MISSING: '.count($missing)."\n";
foreach ($missing as $line) {
    echo " - $line\n";
}

if ($missing === []) {
    echo "Semua method route + helper kritis ada.\n";
    exit(0);
}

exit(1);
