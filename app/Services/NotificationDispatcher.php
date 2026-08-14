<?php

namespace App\Services;

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

    public function notifyClosingReminder(array $payload): bool
    {
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
