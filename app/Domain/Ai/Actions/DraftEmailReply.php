<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Support\Assistant;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Listings\Models\Listing;
use App\Domain\Tenancy\TenantContext;
use App\Support\Money;

/**
 * Suggests a reply to a customer e-mail, in the customer's language, using only the facts of
 * the assigned vehicle file (status, advertised price). The suggestion goes into the reply
 * form; a person edits it, and it is sent only on "Send".
 */
class DraftEmailReply
{
    public function __construct(
        private readonly Assistant $assistant,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(EmailMessage $message, ?string $instruction = null): string
    {
        $message->loadMissing(['stockCycle.vehicle', 'mailbox']);
        $tenant = $this->context->tenant();

        $system = implode("\n", [
            'You draft e-mail replies for a Swiss used-car dealer. The dealer reads and edits every draft before sending.',
            'Reply in the language of the customer\'s e-mail (German, French, Italian or English). Swiss German: never "ß", use "Grüezi" or "Guten Tag", formal "Sie".',
            'Use only the facts given. Do not promise prices, discounts, availability, dates or financing that are not in the facts; where something must be decided by the dealer, write a placeholder in square brackets, e.g. [Termin vorschlagen].',
            'Be short, friendly and concrete. Plain text, no subject line, no markdown. End with a greeting and the dealer name.',
        ]);

        $cycle = $message->stockCycle;
        $listing = $cycle === null ? null : Listing::query()->where('stock_cycle_id', $cycle->getKey())->first()?->setRelation('stockCycle', $cycle);

        $facts = array_filter([
            'dealer' => $tenant?->name,
            'dealer_phone' => $tenant?->phone,
            'dealer_address' => $tenant === null ? null : trim(($tenant->street ?? '').', '.($tenant->zip ?? '').' '.($tenant->city ?? ''), ', '),
            'vehicle' => $cycle?->title(),
            'vehicle_status' => $listing?->availability()->getLabel() ?? $cycle?->status->getLabel(),
            'advertised_price' => $listing !== null && $listing->show_price ? Money::format($listing->price_rp) : null,
            'mileage_km' => $cycle?->mileage_in,
            'first_registration' => $cycle?->vehicle->first_registration_on?->format('m.Y'),
            'customer_name' => $message->from_name,
            'instruction_from_dealer' => $instruction,
        ], fn ($v): bool => $v !== null && $v !== '');

        $prompt = "Facts (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nCustomer e-mail, subject: ".($message->subject ?? '')."\n---\n".mb_substr((string) $message->body_text, 0, 6000)."\n---\nWrite the reply.";

        $text = $this->assistant->ask('email_reply', $system, $prompt, $message, 1200);

        // Swiss spelling (the model sometimes slips): no "ß" in any of the four languages.
        $text = str_replace('ß', 'ss', $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
