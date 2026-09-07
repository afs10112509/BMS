<?php

namespace App\Services;

use App\Jobs\SendClosingWhatsAppJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationDispatcher
{
    public function notifyOwnerApprovalNeeded(array $payload): void
    {
        $this->dispatch('permohonan_transfer', $payload);
    }

    public function notifyReconciliationDifference(array $payload): void
    {
        $this->dispatch('selisih_rekonsiliasi', $payload);
    }

    /**
     * Antrikan pengingat closing: satu penerima per job, dengan jeda antar pesan.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifyClosingReminder(array $payload): bool
    {
        if (! config('services.n8n.webhook_url')) {
            Log::warning('N8N_WEBHOOK_URL kosong, notifikasi tidak dikirim.', [
                'event' => 'reminder_closing',
            ]);

            return false;
        }

        $recipients = array_values($payload['recipients'] ?? []);
        if ($recipients === []) {
            return true;
        }

        $meta = $payload;
        unset($meta['recipients'], $meta['skipped']);

        $delay = max(8, (int) config('bms.reminders.send_delay_seconds', 25));
        $jitter = max(0, (int) config('bms.reminders.send_delay_jitter', 8));
        $cursor = 0;

        foreach ($recipients as $i => $recipient) {
            SendClosingWhatsAppJob::dispatch($meta, $recipient)
                ->delay(now()->addSeconds($cursor));
            $cursor += $delay + ($jitter > 0 ? random_int(0, $jitter) : 0);
        }

        Log::info('Pengingat closing diantrikan bertahap.', [
            'event' => 'reminder_closing',
            'recipients' => count($recipients),
            'delay_seconds' => $delay,
            'estimated_minutes' => (int) ceil($cursor / 60),
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $recipient
     */
    public function dispatchClosingRecipient(array $meta, array $recipient): bool
    {
        $payload = $meta;
        $payload['recipients'] = [$recipient];
        $payload['skipped'] = [];

        return $this->dispatch('reminder_closing', $payload);
    }

    protected function dispatch(string $event, array $payload): bool
    {
        $url = config('services.n8n.webhook_url');

        Log::info('Notifikasi BMS', [
            'event' => $event,
            'payload' => $payload,
        ]);

        if (! $url) {
            Log::warning('N8N_WEBHOOK_URL kosong, notifikasi tidak dikirim.', [
                'event' => $event,
            ]);

            return false;
        }

        try {
            $response = Http::timeout(15)->acceptJson()->asJson()->post($url, [
                'event' => $event,
                'data' => $payload,
            ]);

            if ($response->failed()) {
                Log::warning('Webhook n8n merespons gagal', [
                    'event' => $event,
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Gagal mengirim webhook n8n', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
