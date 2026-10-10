<?php

namespace App\Http\Controllers;

use App\Domain\Chat\Actions\ReceiveWhatsApp;
use App\Domain\Chat\Providers\WhatsApp\WhatsAppCloud;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook of the dealer's WhatsApp Business account: the verification handshake (GET) and the
 * events (POST), each signed by Meta with the dealer's app secret.
 */
class WhatsAppWebhookController
{
    public function __construct(private readonly TenantContext $context) {}

    public function verify(Request $request, string $account): Response
    {
        $record = $this->account($account);
        $token = (string) $request->query('hub_verify_token', (string) $request->query('hub.verify_token'));

        abort_unless($record !== null && $request->query('hub_mode', $request->query('hub.mode')) === 'subscribe'
            && hash_equals((string) $record->setting('verify_token'), $token), 403);

        return response((string) $request->query('hub_challenge', (string) $request->query('hub.challenge')), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $account, ReceiveWhatsApp $receive): Response
    {
        $record = $this->account($account);
        $body = $request->getContent();

        abort_if($record === null, 404);
        abort_unless(WhatsAppCloud::validSignature($record, $body, $request->header('X-Hub-Signature-256')), 401);

        $this->context->set($record->tenant);

        try {
            $receive($record, (array) json_decode($body, true));
        } finally {
            $this->context->clear();
        }

        return response('', 200);
    }

    private function account(string $id): ?IntegrationAccount
    {
        if (! preg_match('/^[0-9a-f-]{36}$/', $id)) {
            return null;
        }

        $account = $this->context->bypass(fn () => IntegrationAccount::query()->withoutGlobalScopes()->with('tenant')
            ->where('provider', IntegrationAccount::WHATSAPP)->where('is_active', true)->find($id));

        return $account !== null && $account->tenant?->status === Tenant::STATUS_ACTIVE ? $account : null;
    }
}
