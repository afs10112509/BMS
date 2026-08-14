<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    public function directory(): string
    {
        $dir = (string) config('bms.backup.directory');
        File::ensureDirectoryExists($dir, 0750);

        return $dir;
    }

    public function confirmPhrase(): string
    {
        return (string) config('bms.backup.restore_confirm_phrase', 'PULIHKAN');
    }

    /**
     * @return array{
     *   enabled:bool,
     *   time:string,
     *   timezone:string,
     *   last_run_at:?string,
     *   last_status:?string,
     *   last_filename:?string,
     *   last_error:?string,
     *   next_run_at:?string
     * }
     */
    public function getSchedule(): array
    {
        $data = $this->readScheduleFile();
        $enabled = (bool) ($data['enabled'] ?? config('bms.backup.schedule_enabled', true));
        $time = $this->normalizeScheduleTime((string) ($data['time'] ?? config('bms.backup.schedule_time', '02:00')));
        $tz = (string) config('app.timezone', 'Asia/Jayapura');

        return [
            'enabled' => $enabled,
            'time' => $time,
            'timezone' => $tz,
            'last_run_at' => $data['last_run_at'] ?? null,
            'last_status' => $data['last_status'] ?? null,
            'last_filename' => $data['last_filename'] ?? null,
            'last_error' => $data['last_error'] ?? null,
            'next_run_at' => $enabled ? $this->estimateNextRunAt($time, $tz) : null,
        ];
    }

    /**
     * @param  array{enabled?:bool,time?:string}  $input
     * @return array{
     *   enabled:bool,
     *   time:string,
     *   timezone:string,
     *   last_run_at:?string,
     *   last_status:?string,
     *   last_filename:?string,
     *   last_error:?string,
     *   next_run_at:?string
     * }
     */
    public function updateSchedule(array $input): array
    {
        $current = $this->readScheduleFile();
        if (array_key_exists('enabled', $input)) {
            $current['enabled'] = (bool) $input['enabled'];
        }
        if (array_key_exists('time', $input) && $input['time'] !== null && $input['time'] !== '') {
            $current['time'] = $this->normalizeScheduleTime((string) $input['time']);
        } else {
            $current['time'] = $this->normalizeScheduleTime((string) ($current['time'] ?? config('bms.backup.schedule_time', '02:00')));
        }

        $this->writeScheduleFile($current);

        return $this->getSchedule();
    }

    public function shouldRunScheduledNow(): bool
    {
        $schedule = $this->getSchedule();
        if (! $schedule['enabled']) {
            return false;
        }

        $tz = $schedule['timezone'];
        $now = now($tz);
        if ($now->format('H:i') !== $schedule['time']) {
            return false;
        }

        $last = $schedule['last_run_at'] ?? null;
        if ($last) {
            try {
                if (\Carbon\Carbon::parse($last, $tz)->isSameDay($now)) {
                    return false;
                }
            } catch (\Throwable) {
                // lanjutkan
            }
        }

        return true;
    }

    public function markScheduleRun(bool $ok, ?string $filename, ?string $error): void
    {
        $current = $this->readScheduleFile();
        $current['enabled'] = (bool) ($current['enabled'] ?? config('bms.backup.schedule_enabled', true));
        $current['time'] = $this->normalizeScheduleTime((string) ($current['time'] ?? config('bms.backup.schedule_time', '02:00')));
        $current['last_run_at'] = now()->toIso8601String();
        $current['last_status'] = $ok ? 'ok' : 'error';
        $current['last_filename'] = $filename;
        $current['last_error'] = $ok ? null : ($error ?: 'Gagal');
        $this->writeScheduleFile($current);
    }

    protected function scheduleFilePath(): string
    {
        $path = (string) config('bms.backup.schedule_file', storage_path('app/backups/schedule.json'));
        File::ensureDirectoryExists(dirname($path), 0750);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readScheduleFile(): array
    {
        $path = $this->scheduleFilePath();
        if (! is_file($path)) {
            return [
                'enabled' => (bool) config('bms.backup.schedule_enabled', true),
                'time' => $this->normalizeScheduleTime((string) config('bms.backup.schedule_time', '02:00')),
            ];
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [
            'enabled' => (bool) config('bms.backup.schedule_enabled', true),
            'time' => $this->normalizeScheduleTime((string) config('bms.backup.schedule_time', '02:00')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeScheduleFile(array $data): void
    {
        $path = $this->scheduleFilePath();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException('Gagal menyimpan pengaturan jadwal backup.');
        }
        @chmod($path, 0640);
    }

    protected function normalizeScheduleTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            throw new RuntimeException('Format jam tidak valid. Gunakan HH:MM, contoh: 02:00.');
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    protected function estimateNextRunAt(string $time, string $tz): string
    {
        [$h, $i] = array_map('intval', explode(':', $time));
        $next = now($tz)->setTime($h, $i, 0);
        if ($next->lessThanOrEqualTo(now($tz))) {
            $next = $next->addDay();
        }

        return $next->toIso8601String();
    }

    /**
     * @return list<array{filename:string,size:int,size_label:string,created_at:string}>
     */
    public function listBackups(int $limit = 20): array
    {
        $dir = $this->directory();
        $files = collect(File::files($dir))
            ->filter(fn ($f) => preg_match('/^bms_db_\d{8}_\d{6}\.dump$/', $f->getFilename()) === 1)
            ->filter(fn ($f) => $f->getSize() >= 64)
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->take($limit)
            ->values();

        return $files->map(function ($f) {
            $size = (int) $f->getSize();

            return [
                'filename' => $f->getFilename(),
                'size' => $size,
                'size_label' => $this->formatBytes($size),
                'created_at' => date('c', $f->getMTime()),
            ];
        })->all();
    }

    /**
     * @return array{filename:string,path:string,size:int,size_label:string,created_at:string}
     */
    public function createBackup(): array
    {
        $stamp = now()->format('Ymd_His');
        $filename = "bms_db_{$stamp}.dump";
        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;

        $this->runPgDump($path);

        if (! is_file($path) || filesize($path) < 64) {
            @unlink($path);
            throw new RuntimeException('File backup kosong atau gagal dibuat.');
        }

        $this->pruneOldBackups();

        $size = (int) filesize($path);

        return [
            'filename' => $filename,
            'path' => $path,
            'size' => $size,
            'size_label' => $this->formatBytes($size),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{filename:string,size:int,size_label:string,created_at:string,safety_backup:?string}
     */
    public function restoreBackup(string $filename, bool $makeSafetyBackup = true): array
    {
        $path = $this->resolvePath($filename);

        $safetyName = null;
        if ($makeSafetyBackup) {
            try {
                $safety = $this->createBackup();
                $safetyName = $safety['filename'];
            } catch (RuntimeException $e) {
                // Lanjutkan restore jika safety backup gagal (DB mungkin sudah bermasalah).
                $safetyName = null;
            }
        }

        $this->runPgRestore($path);

        // Putuskan koneksi lama agar skema baru terbaca.
        try {
            DB::purge();
            DB::reconnect();
        } catch (\Throwable) {
            // ignore
        }

        try {
            Artisan::call('optimize:clear');
        } catch (\Throwable) {
            // ignore
        }

        $size = (int) filesize($path);

        return [
            'filename' => $filename,
            'size' => $size,
            'size_label' => $this->formatBytes($size),
            'created_at' => date('c', filemtime($path) ?: time()),
            'safety_backup' => $safetyName,
        ];
    }

    /**
     * Simpan dump yang diunggah ke folder backup (format pg_dump -Fc).
     *
     * @return array{filename:string,size:int,size_label:string,created_at:string}
     */
    public function storeUploadedDump(UploadedFile $file): array
    {
        $maxKb = max(1024, (int) config('bms.backup.upload_max_kb', 20480));
        if ($file->getSize() > $maxKb * 1024) {
            throw new RuntimeException('Ukuran file melebihi batas unggahan (maks. '.$this->formatBytes($maxKb * 1024).').');
        }

        $tmp = $file->getRealPath();
        if (! $tmp || ! is_file($tmp)) {
            throw new RuntimeException('File unggahan tidak valid.');
        }

        $magic = (string) @file_get_contents($tmp, false, null, 0, 5);
        if ($magic !== 'PGDMP') {
            throw new RuntimeException('File bukan dump PostgreSQL format kustom (pg_dump -Fc).');
        }

        $stamp = now()->format('Ymd_His');
        $filename = "bms_db_{$stamp}.dump";
        $dest = $this->directory().DIRECTORY_SEPARATOR.$filename;

        if (! @copy($tmp, $dest)) {
            throw new RuntimeException('Gagal menyimpan file backup yang diunggah.');
        }

        @chmod($dest, 0640);
        $this->pruneOldBackups();

        $size = (int) filesize($dest);

        return [
            'filename' => $filename,
            'size' => $size,
            'size_label' => $this->formatBytes($size),
            'created_at' => now()->toIso8601String(),
        ];
    }

    public function resolvePath(string $filename): string
    {
        if (preg_match('/^bms_db_\d{8}_\d{6}\.dump$/', $filename) !== 1) {
            throw new RuntimeException('Nama file backup tidak valid.');
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;
        $realDir = realpath($this->directory());
        $realFile = realpath($path);

        if (! $realDir || ! $realFile || ! str_starts_with($realFile, $realDir) || ! is_file($realFile)) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }

        return $realFile;
    }

    protected function connectionConfig(): array
    {
        $conn = config('database.connections.'.config('database.default'));
        if (! is_array($conn) || ($conn['driver'] ?? '') !== 'pgsql') {
            throw new RuntimeException('Backup/restore hanya didukung untuk database PostgreSQL.');
        }

        $db = (string) ($conn['database'] ?? '');
        $user = (string) ($conn['username'] ?? '');
        if ($db === '' || $user === '') {
            throw new RuntimeException('Konfigurasi database tidak lengkap.');
        }

        return [
            'db' => $db,
            'user' => $user,
            'host' => (string) ($conn['host'] ?? '127.0.0.1'),
            'port' => (string) ($conn['port'] ?? '5432'),
            'password' => (string) ($conn['password'] ?? ''),
        ];
    }

    protected function runPgDump(string $outputPath): void
    {
        $c = $this->connectionConfig();
        $timeout = max(60, (int) config('bms.backup.timeout', 300));
        $container = trim((string) config('bms.backup.docker_container', ''));

        if ($container !== '') {
            $docker = (string) config('bms.backup.docker_bin', 'docker');
            $process = new Process([
                $docker,
                'exec',
                '-e',
                'PGPASSWORD='.$c['password'],
                $container,
                'pg_dump',
                '-U',
                $c['user'],
                '-d',
                $c['db'],
                '--no-owner',
                '--no-acl',
                '-Fc',
            ]);
        } else {
            $pgDump = $this->resolvePgBinary('pg_dump', (string) config('bms.backup.pg_dump', 'pg_dump'));
            $env = array_merge($_ENV, $_SERVER, [
                'PGPASSWORD' => $c['password'],
            ]);
            $process = new Process([
                $pgDump,
                '-h',
                $c['host'],
                '-p',
                $c['port'],
                '-U',
                $c['user'],
                '-d',
                $c['db'],
                '--no-owner',
                '--no-acl',
                '-Fc',
                '-f',
                $outputPath,
            ], null, $env);
        }

        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException(
                'Gagal menjalankan pg_dump'.($err !== '' ? ': '.$err : '.')
            );
        }

        if ($container !== '') {
            $binary = $process->getOutput();
            if ($binary === '') {
                throw new RuntimeException('Output pg_dump dari Docker kosong.');
            }
            if (file_put_contents($outputPath, $binary) === false) {
                throw new RuntimeException('Gagal menulis file backup.');
            }
        }
    }

    protected function runPgRestore(string $dumpPath): void
    {
        $c = $this->connectionConfig();
        $timeout = max(60, (int) config('bms.backup.timeout', 300));
        $container = trim((string) config('bms.backup.docker_container', ''));

        if ($container !== '') {
            // Salin dump ke container lalu restore di dalamnya.
            $docker = (string) config('bms.backup.docker_bin', 'docker');
            $remote = '/tmp/bms_restore_'.basename($dumpPath);
            $copy = new Process([$docker, 'cp', $dumpPath, $container.':'.$remote]);
            $copy->setTimeout(120);
            $copy->run();
            if (! $copy->isSuccessful()) {
                throw new RuntimeException('Gagal menyalin dump ke container Postgres.');
            }

            $process = new Process([
                $docker,
                'exec',
                '-e',
                'PGPASSWORD='.$c['password'],
                $container,
                'pg_restore',
                '-U',
                $c['user'],
                '-d',
                $c['db'],
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-acl',
                $remote,
            ]);
            $process->setTimeout($timeout);
            $process->run();
            // bersihkan file sementara
            (new Process([$docker, 'exec', $container, 'rm', '-f', $remote]))->run();
        } else {
            $pgRestore = $this->resolvePgBinary('pg_restore', (string) config('bms.backup.pg_restore', 'pg_restore'));
            $env = array_merge($_ENV, $_SERVER, [
                'PGPASSWORD' => $c['password'],
            ]);
            $process = new Process([
                $pgRestore,
                '-h',
                $c['host'],
                '-p',
                $c['port'],
                '-U',
                $c['user'],
                '-d',
                $c['db'],
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-acl',
                $dumpPath,
            ], null, $env);
            $process->setTimeout($timeout);
            $process->run();
        }

        // pg_restore sering exit 1 karena warning non-fatal (role/extension).
        $code = $process->getExitCode();
        $err = trim($process->getErrorOutput() ?: $process->getOutput());
        if ($code !== 0 && $code !== 1) {
            throw new RuntimeException(
                'Gagal menjalankan pg_restore'.($err !== '' ? ': '.$err : '.')
            );
        }

        // Deteksi kegagalan diam-diam (binary tidak ketemu / tidak ada aksi).
        if ($code === 1 && $err !== '' && preg_match('/not recognized|not found|No such file|cannot find/i', $err)) {
            throw new RuntimeException('pg_restore tidak ditemukan. Set BMS_PG_RESTORE ke path penuh binary.');
        }
    }

    protected function resolvePgBinary(string $name, string $configured): string
    {
        $configured = trim($configured);
        if ($configured !== '' && $configured !== $name && is_file($configured)) {
            return $configured;
        }

        // Jika BMS_PG_DUMP = .../pg_dump.exe, pakai sibling pg_restore.exe
        $dump = trim((string) config('bms.backup.pg_dump', ''));
        if ($dump !== '' && is_file($dump)) {
            $sibling = dirname($dump).DIRECTORY_SEPARATOR.str_replace('pg_dump', $name, basename($dump));
            if (is_file($sibling)) {
                return $sibling;
            }
            // fallback: same dir + name + .exe
            $alt = dirname($dump).DIRECTORY_SEPARATOR.$name.(str_ends_with(strtolower($dump), '.exe') ? '.exe' : '');
            if (is_file($alt)) {
                return $alt;
            }
        }

        return $configured !== '' ? $configured : $name;
    }

    protected function pruneOldBackups(): void
    {
        $keep = max(1, (int) config('bms.backup.keep', 10));
        $files = collect(File::files($this->directory()))
            ->filter(fn ($f) => preg_match('/^bms_db_\d{8}_\d{6}\.dump$/', $f->getFilename()) === 1)
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        foreach ($files->slice($keep) as $old) {
            @unlink($old->getPathname());
        }
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 2).' MB';
    }
}
