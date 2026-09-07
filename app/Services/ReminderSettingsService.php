<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ReminderSettingsService
{
    public const TYPE_CLOSING = 'closing';

    /**
     * @return list<string>
     */
    public function typeKeys(): array
    {
        return array_keys((array) config('bms.reminders.types', []));
    }

    public function isKnownType(string $type): bool
    {
        return in_array($type, $this->typeKeys(), true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function positionOptions(): array
    {
        $options = [];
        foreach (Employee::REMINDER_POSITION_CODES as $code) {
            $options[] = [
                'value' => $code,
                'label' => Employee::POSITION_LABELS[$code],
            ];
        }

        return $options;
    }

    /**
     * @return array{
     *     type: string,
     *     label: string,
     *     description: string,
     *     scope: string,
     *     enabled: bool,
     *     time: string,
     *     timezone: string,
     *     positions: list<string>,
     *     position_labels: list<string>,
     *     last_run_at: ?string,
     *     last_status: ?string,
     *     last_error: ?string,
     *     last_sent: int,
     *     next_run_at: ?string
     * }
     */
    public function get(string $type): array
    {
        $meta = $this->typeMeta($type);
        $stored = $this->readFile()[$type] ?? [];
        $enabled = array_key_exists('enabled', $stored)
            ? (bool) $stored['enabled']
            : (bool) ($meta['default_enabled'] ?? true);
        $time = $this->normalizeTime((string) ($stored['time'] ?? $meta['default_time'] ?? '08:00'));
        $positions = $this->normalizePositions($stored['positions'] ?? ($meta['default_positions'] ?? []));
        $tz = (string) config('app.timezone', 'Asia/Jayapura');

        return [
            'type' => $type,
            'label' => (string) ($meta['label'] ?? $type),
            'description' => (string) ($meta['description'] ?? ''),
            'scope' => (string) ($meta['scope'] ?? ''),
            'enabled' => $enabled,
            'time' => $time,
            'timezone' => $tz,
            'positions' => $positions,
            'position_labels' => array_values(array_map(
                fn (string $code) => Employee::POSITION_LABELS[$code] ?? $code,
                $positions,
            )),
            'last_run_at' => isset($stored['last_run_at']) ? (string) $stored['last_run_at'] : null,
            'last_status' => isset($stored['last_status']) ? (string) $stored['last_status'] : null,
            'last_error' => isset($stored['last_error']) ? (string) $stored['last_error'] : null,
            'last_sent' => (int) ($stored['last_sent'] ?? 0),
            'next_run_at' => $enabled ? $this->estimateNextRunAt($time, $tz) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->typeKeys() as $type) {
            $out[] = $this->get($type);
        }

        return $out;
    }

    /**
     * @param  array{enabled?: bool, time?: string, positions?: mixed}  $input
     * @return array<string, mixed>
     */
    public function update(string $type, array $input): array
    {
        $this->typeMeta($type);
        $all = $this->readFile();
        $current = is_array($all[$type] ?? null) ? $all[$type] : [];
        $meta = $this->typeMeta($type);

        if (array_key_exists('enabled', $input)) {
            $current['enabled'] = (bool) $input['enabled'];
        } else {
            $current['enabled'] = (bool) ($current['enabled'] ?? $meta['default_enabled'] ?? true);
        }

        if (array_key_exists('time', $input) && $input['time'] !== null && $input['time'] !== '') {
            $current['time'] = $this->normalizeTime((string) $input['time']);
        } else {
            $current['time'] = $this->normalizeTime((string) ($current['time'] ?? $meta['default_time'] ?? '08:00'));
        }

        if (array_key_exists('positions', $input)) {
            $current['positions'] = $this->normalizePositions($input['positions']);
        } else {
            $current['positions'] = $this->normalizePositions($current['positions'] ?? ($meta['default_positions'] ?? []));
        }

        if (! empty($current['enabled']) && $current['positions'] === []) {
            throw new RuntimeException('Pilih minimal satu jabatan penerima.');
        }

        $all[$type] = $current;
        $this->writeFile($all);

        return $this->get($type);
    }

    public function shouldRunNow(string $type): bool
    {
        $settings = $this->get($type);
        if (! $settings['enabled']) {
            return false;
        }
        if ($settings['positions'] === []) {
            return false;
        }

        $tz = $settings['timezone'];
        $now = now($tz);
        if ($now->format('H:i') !== $settings['time']) {
            return false;
        }

        $last = $settings['last_run_at'] ?? null;
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

    public function markRun(string $type, bool $ok, int $sent, ?string $error): void
    {
        $this->typeMeta($type);
        $all = $this->readFile();
        $current = is_array($all[$type] ?? null) ? $all[$type] : [];
        $meta = $this->typeMeta($type);
        $current['enabled'] = (bool) ($current['enabled'] ?? $meta['default_enabled'] ?? true);
        $current['time'] = $this->normalizeTime((string) ($current['time'] ?? $meta['default_time'] ?? '08:00'));
        $current['positions'] = $this->normalizePositions($current['positions'] ?? ($meta['default_positions'] ?? []));
        $current['last_run_at'] = now()->toIso8601String();
        $current['last_status'] = $ok ? 'ok' : 'error';
        $current['last_sent'] = $sent;
        $current['last_error'] = $ok ? null : ($error ?: 'Gagal');
        $all[$type] = $current;
        $this->writeFile($all);
    }

    /**
     * @return array<string, mixed>
     */
    protected function typeMeta(string $type): array
    {
        $meta = config('bms.reminders.types.'.$type);
        if (! is_array($meta)) {
            throw new RuntimeException('Jenis pengingat tidak dikenali.');
        }

        return $meta;
    }

    /**
     * @param  mixed  $positions
     * @return list<string>
     */
    public function normalizePositions(mixed $positions): array
    {
        $codes = Employee::normalizePositions($positions);

        return array_values(array_filter(
            $codes,
            fn (string $code) => in_array($code, Employee::REMINDER_POSITION_CODES, true),
        ));
    }

    protected function settingsFilePath(): string
    {
        $path = (string) config('bms.reminders.file', storage_path('app/reminders/settings.json'));
        File::ensureDirectoryExists(dirname($path), 0750);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readFile(): array
    {
        $path = $this->settingsFilePath();
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeFile(array $data): void
    {
        $path = $this->settingsFilePath();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException('Gagal menyimpan pengaturan pengingat.');
        }
        @chmod($path, 0640);
    }

    protected function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            throw new RuntimeException('Format jam tidak valid. Gunakan HH:MM, contoh: 08:00.');
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
}
