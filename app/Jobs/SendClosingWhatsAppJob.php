<?php

namespace App\Jobs;

use App\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendClosingWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $recipient
     */
    public function __construct(
        public array $meta,
        public array $recipient,
    ) {}

    public function handle(NotificationDispatcher $notifier): void
    {
        $ok = $notifier->dispatchClosingRecipient($this->meta, $this->recipient);
        if (! $ok) {
            throw new \RuntimeException('Webhook n8n gagal saat mengirim pengingat closing.');
        }
    }
}
