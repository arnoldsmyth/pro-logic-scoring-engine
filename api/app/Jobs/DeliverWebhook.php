<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Delivers one webhook event (docs/05): JSON POST, HMAC-SHA256 signature of
 * the raw body in X-Signature, event name in X-Event. Retries with backoff;
 * every attempt is reflected on the webhook_deliveries row.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> seconds between attempts */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $secret,
    ) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);
        if ($delivery === null || $delivery->status === 'delivered') {
            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $delivery->increment('attempts');

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Event' => $delivery->event,
                    'X-Signature' => hash_hmac('sha256', $body, $this->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($delivery->url);

            $delivery->forceFill(['response_status' => $response->status()]);
            if ($response->successful()) {
                $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'last_error' => null])->save();

                return;
            }
            $delivery->forceFill(['last_error' => 'HTTP '.$response->status()])->save();
            $this->failOrRetry($delivery);
        } catch (\Throwable $e) {
            $delivery->forceFill(['last_error' => $e->getMessage()])->save();
            $this->failOrRetry($delivery);
        }
    }

    private function failOrRetry(WebhookDelivery $delivery): void
    {
        if ($this->attempts() >= $this->tries) {
            $delivery->forceFill(['status' => 'failed'])->save();

            return;
        }

        $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
    }
}
