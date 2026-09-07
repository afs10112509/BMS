<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Closing\ClosingReminderService;
use App\Services\NotificationDispatcher;
use App\Services\ReminderSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ReminderController extends Controller
{
    public function __construct(
        protected ReminderSettingsService $settings,
        protected ClosingReminderService $closingReminders,
        protected NotificationDispatcher $notifier,
    ) {}

    public function index(): JsonResponse
    {
        $types = [];
        foreach ($this->settings->list() as $item) {
            if ($item['type'] === ReminderSettingsService::TYPE_CLOSING) {
                $item['preview'] = $this->closingPreview($item['positions']);
            }
            $types[] = $item;
        }

        return response()->json([
            'message' => 'Pengaturan pengingat berhasil diambil.',
            'data' => [
                'types' => $types,
                'position_options' => $this->settings->positionOptions(),
            ],
            'meta' => [
                'timezone' => (string) config('app.timezone', 'Asia/Jayapura'),
                'webhook_url' => (string) (config('services.n8n.webhook_url') ?: ''),
                'note' => 'Pengingat hanya untuk Owner. n8n hanya mengirim WhatsApp.',
            ],
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        if (! $this->settings->isKnownType($type)) {
            return response()->json([
                'message' => 'Jenis pengingat tidak dikenali.',
            ], 404);
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'time' => ['required', 'string', 'regex:/^([01]?\d|2[0-3]):([0-5]\d)$/'],
            'positions' => ['present', 'array'],
            'positions.*' => ['string'],
        ], [
            'time.regex' => 'Format jam tidak valid. Gunakan HH:MM, contoh: 08:00.',
            'positions.present' => 'Pilih jabatan penerima.',
        ]);

        try {
            $saved = $this->settings->update($type, [
                'enabled' => (bool) $data['enabled'],
                'time' => $data['time'],
                'positions' => $data['positions'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Gagal menyimpan pengaturan pengingat.',
            ], 422);
        }

        if ($type === ReminderSettingsService::TYPE_CLOSING) {
            $saved['preview'] = $this->closingPreview($saved['positions']);
        }

        return response()->json([
            'message' => 'Pengaturan pengingat disimpan.',
            'data' => $saved,
        ]);
    }

    public function sendClosing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'positions' => ['nullable', 'array'],
            'positions.*' => ['string'],
        ]);

        $saved = $this->settings->get(ReminderSettingsService::TYPE_CLOSING);
        $positions = array_key_exists('positions', $data) && $data['positions'] !== null
            ? $this->settings->normalizePositions($data['positions'])
            : $saved['positions'];

        if ($positions === []) {
            return response()->json([
                'message' => 'Pilih minimal satu jabatan penerima sebelum mengirim.',
            ], 422);
        }

        $tz = (string) config('app.timezone', 'Asia/Jayapura');
        $bundle = $this->closingReminders->build(Carbon::now($tz)->subDay()->startOfDay(), $positions);
        $sent = count($bundle['recipients']);

        if ($sent === 0) {
            return response()->json([
                'message' => 'Tidak ada penerima yang bisa dikirimi (jabatan tidak cocok atau nomor HP kosong).',
                'data' => $this->closingPayload($saved, $positions, $bundle, false),
            ], 422);
        }

        $ok = $this->notifier->notifyClosingReminder($bundle);
        if (! $ok) {
            $this->settings->markRun(ReminderSettingsService::TYPE_CLOSING, false, 0, 'Webhook n8n gagal atau N8N_WEBHOOK_URL belum diisi.');

            return response()->json([
                'message' => 'Gagal mengirim ke n8n. Periksa N8N_WEBHOOK_URL dan workflow WhatsApp.',
                'data' => $this->closingPayload($this->settings->get(ReminderSettingsService::TYPE_CLOSING), $positions, $bundle, false),
            ], 502);
        }

        $this->settings->markRun(ReminderSettingsService::TYPE_CLOSING, true, $sent, null);
        $fresh = $this->settings->get(ReminderSettingsService::TYPE_CLOSING);

        return response()->json([
            'message' => 'Pengingat closing dijadwalkan ke '.$sent.' penerima, dikirim satu per satu dengan jeda ±25 detik.',
            'data' => $this->closingPayload($fresh, $positions, $bundle, true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $positions
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    protected function closingPayload(array $settings, array $positions, array $bundle, bool $sentOk): array
    {
        $settings['positions'] = $positions;
        $settings['preview'] = $this->previewFromBundle($bundle);
        $settings['sent'] = $sentOk ? count($bundle['recipients']) : 0;

        return $settings;
    }

    /**
     * @param  list<string>  $positions
     * @return array{recipients: int, skipped_no_phone: int, skipped_position: int}
     */
    protected function closingPreview(array $positions): array
    {
        $tz = (string) config('app.timezone', 'Asia/Jayapura');
        $bundle = $this->closingReminders->build(Carbon::now($tz)->subDay()->startOfDay(), $positions);

        return $this->previewFromBundle($bundle);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{recipients: int, skipped_no_phone: int, skipped_position: int}
     */
    protected function previewFromBundle(array $bundle): array
    {
        $skipped = collect($bundle['skipped'] ?? []);

        return [
            'recipients' => count($bundle['recipients'] ?? []),
            'skipped_no_phone' => $skipped->where('reason', 'no_phone')->count(),
            'skipped_position' => $skipped->where('reason', 'position')->count(),
        ];
    }
}
