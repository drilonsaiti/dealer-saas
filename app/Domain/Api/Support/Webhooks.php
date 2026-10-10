<?php

namespace App\Domain\Api\Support;

use App\Domain\Api\Jobs\DeliverWebhook;
use App\Domain\Api\Models\WebhookDelivery;
use App\Domain\Api\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Sends an event to every endpoint of the dealer that listens to it: one delivery row each
 * (the sync log), delivered by a queued job with retries after the transaction commits.
 */
class Webhooks
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(string $event, array $data): int
    {
        $endpoints = WebhookEndpoint::query()->where('is_active', true)->get()->filter(fn (WebhookEndpoint $e): bool => $e->listensTo($event));

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::create([
                'endpoint_id' => $endpoint->getKey(),
                'event' => $event,
                'payload' => ['id' => (string) Str::uuid7(), 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data],
            ]);

            DeliverWebhook::dispatch($delivery->getKey())->afterCommit();
        }

        return $endpoints->count();
    }

    /**
     * Header value "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">" (like Stripe), so the
     * receiver can check origin and freshness.
     */
    public static function signature(string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
