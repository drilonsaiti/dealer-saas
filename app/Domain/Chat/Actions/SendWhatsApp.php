<?php

namespace App\Domain\Chat\Actions;

use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Chat\Providers\WhatsApp\WhatsAppCloud;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\IntegrationException;
use App\Support\BusinessRuleException;

/**
 * A reply to a customer on WhatsApp. WhatsApp allows free text only within 24 hours after the
 * customer's last message; later only approved templates (not offered here yet).
 */
class SendWhatsApp
{
    public function __construct(private readonly WhatsAppCloud $whatsapp) {}

    public function __invoke(string $phone, string $body, ?ChatMessage $context = null): ChatMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new BusinessRuleException(__('Enter a message.'));
        }

        $account = IntegrationAccount::query()->active()->where('provider', IntegrationAccount::WHATSAPP)->first()
            ?? throw new BusinessRuleException(__('Connect WhatsApp Business first (Settings → Integrations).'));

        if (! self::windowOpen($phone)) {
            throw new BusinessRuleException(__('The customer has not written in the last 24 hours. WhatsApp only allows approved templates then; call or write an e-mail instead.'));
        }

        $last = $context ?? ChatMessage::query()->where('phone', $phone)->latest('created_at')->first();
        $message = new ChatMessage(['integration_account_id' => $account->getKey(), 'direction' => ChatMessage::OUT, 'phone' => $phone, 'body' => $body, 'status' => 'sending']);
        $message->forceFill(['party_id' => $last?->party_id, 'stock_cycle_id' => $last?->stock_cycle_id, 'contact_name' => $last?->contact_name])->save();

        try {
            $id = $this->whatsapp->sendText($account, $phone, $body);
        } catch (IntegrationException $e) {
            $message->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();

            throw new BusinessRuleException($e->getMessage());
        }

        $message->forceFill(['status' => 'sent', 'external_id' => $id])->save();
        ChatMessage::query()->where('phone', $phone)->where('direction', ChatMessage::IN)->whereNull('read_at')->update(['read_at' => now()]);

        return $message;
    }

    public static function windowOpen(string $phone): bool
    {
        return ChatMessage::query()->where('phone', $phone)->where('direction', ChatMessage::IN)
            ->where('created_at', '>=', now()->subHours((int) config('integrations.whatsapp.service_window_hours', 24)))
            ->exists();
    }
}
