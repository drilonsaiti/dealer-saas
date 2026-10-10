<?php

namespace App\Domain\Api\Jobs;

use App\Domain\Api\Models\WebhookDelivery;
use App\Domain\Api\Models\WebhookEndpoint;
use App\Domain\Api\Support\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts one delivery, signed; retried with growing pauses. The answer is logged; an endpoint
 * that keeps failing is switched off.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public string $deliveryId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 21600];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === 'delivered' || ! $delivery->endpoint->is_active) {
            return;
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = now()->getTimestamp();
        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        try {
            $response = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'DealerSaaS-Webhooks/1',
                'X-Dealer-Event' => $delivery->event,
                'X-Dealer-Delivery' => $delivery->getKey(),
                'X-Dealer-Signature' => Webhooks::signature($delivery->endpoint->secret, $body, $time),
            ])->withBody($body, 'application/json')->post($delivery->endpoint->url);

            $ok = $response->successful();
            $code = $response->status();
            $answer = mb_substr($response->body(), 0, 2000);
        } catch (Throwable $e) {
            $ok = false;
            $code = null;
            $answer = mb_substr($e->getMessage(), 0, 2000);
        }

        $delivery->forceFill([
            'status' => $ok ? 'delivered' : ($this->attempts() >= $this->tries ? 'failed' : 'pending'),
            'response_code' => $code,
            'response_body' => $answer,
            'delivered_at' => $ok ? now() : null,
        ])->save();

        $this->recordOn($delivery->endpoint, $ok);

        if (! $ok) {
            $this->release($this->backoff()[min($this->attempts() - 1, 4)] ?? 3600);
        }
    }

    private function recordOn(WebhookEndpoint $endpoint, bool $ok): void
    {
        if ($ok) {
            $endpoint->forceFill(['consecutive_failures' => 0, 'last_success_at' => now()])->save();

            return;
        }

        $failures = $endpoint->consecutive_failures + 1;
        $endpoint->forceFill([
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
            'is_active' => $failures < WebhookEndpoint::MAX_FAILURES,
        ])->save();
    }
}
