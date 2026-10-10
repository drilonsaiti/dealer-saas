<?php

namespace App\Domain\Chat\Providers\WhatsApp;

use App\Domain\Integrations\Contracts\Integration;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\IntegrationException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Platform (Cloud API): send text messages, download media, verify webhook
 * signatures. The dealer's own business number, token and app secret.
 */
class WhatsAppCloud implements Integration
{
    public function key(): string
    {
        return IntegrationAccount::WHATSAPP;
    }

    public function test(IntegrationAccount $account): void
    {
        $this->guard(fn (): Response => $this->client($account)->get($this->url($account->credential('phone_number_id') ?? ''), ['fields' => 'display_phone_number,verified_name']));
    }

    /**
     * @return string the message id from Meta
     */
    public function sendText(IntegrationAccount $account, string $to, string $body): string
    {
        $response = $this->guard(fn (): Response => $this->client($account)->post($this->url(($account->credential('phone_number_id') ?? '').'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($to, '+'),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]));

        $id = $response->json('messages.0.id');

        if (! is_string($id)) {
            throw new IntegrationException(__('WhatsApp did not confirm the message.'), $response->status(), retryable: false);
        }

        return $id;
    }

    /**
     * @return array{content: string, mime: string}
     */
    public function media(IntegrationAccount $account, string $mediaId): array
    {
        $meta = $this->guard(fn (): Response => $this->client($account)->get($this->url($mediaId)));
        $url = $meta->json('url');

        if (! is_string($url)) {
            throw new IntegrationException(__('WhatsApp did not give the file.'), retryable: false);
        }

        $file = $this->guard(fn (): Response => $this->client($account)->get($url));

        return ['content' => $file->body(), 'mime' => (string) ($meta->json('mime_type') ?? $file->header('Content-Type'))];
    }

    public static function validSignature(IntegrationAccount $account, string $body, ?string $header): bool
    {
        $secret = $account->credential('app_secret');

        if ($secret === null || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $header);
    }

    private function client(IntegrationAccount $account): PendingRequest
    {
        $token = $account->credential('access_token');

        if ($token === null || $account->credential('phone_number_id') === null) {
            throw new IntegrationException(__('Enter the phone number ID and the access token from Meta.'), retryable: false);
        }

        return Http::acceptJson()->withToken($token)->timeout((int) config('integrations.whatsapp.timeout', 20));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('integrations.whatsapp.base_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  Closure(): Response  $call
     */
    private function guard(Closure $call): Response
    {
        try {
            return $this->check($call());
        } catch (ConnectionException $e) {
            throw new IntegrationException(__('WhatsApp cannot be reached: :reason', ['reason' => $e->getMessage()]));
        }
    }

    private function check(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $detail = (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200));

        throw new IntegrationException(match (true) {
            in_array($response->status(), [401, 403], true) => __('WhatsApp refused access (:code): :detail', ['code' => $response->status(), 'detail' => $detail]),
            default => __('WhatsApp answered with an error (:code): :detail', ['code' => $response->status(), 'detail' => $detail]),
        }, $response->status(), retryable: $response->serverError() || $response->status() === 429);
    }
}
