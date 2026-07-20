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

    protected function dispatch(string $event, array $payload): void
    {
        $url = config('services.n8n.webhook_url');

        Log::info('Notifikasi keuangan', [
            'event' => $event,
            'payload' => $payload,
        ]);

        if (! $url) {
            return;
        }

        try {
            Http::timeout(5)->post($url, [
                'event' => $event,
                'data' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal mengirim webhook n8n', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
