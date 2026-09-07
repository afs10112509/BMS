<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('attendance:prune-photos --months=3')->dailyAt('02:15');
Schedule::command('closing:remind')->dailyAt('08:00');

// Backup DB otomatis: dicek tiap menit, jam diatur Owner (Asia/Jayapura).
Schedule::command('db:backup --scheduled')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/db-backup-schedule.log'));
