<?php

namespace App\Domain\Portal\Actions;

use App\Domain\Inbox\Actions\SaveEmailDraft;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Portal\Models\PortalLink;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\TenantContext;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the buyer's portal link (an earlier one stops working). Valid for a year by default.
 * Optionally prepares an e-mail to the buyer in the inbox.
 */
class IssuePortalLink
{
    public const VALID_DAYS = 365;

    public function __construct(
        private readonly SaveEmailDraft $drafts,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{0: PortalLink, 1: string}
     */
    public function __invoke(Sale $sale, int $days = self::VALID_DAYS): array
    {
        if ($sale->status === SaleStatus::Cancelled || $sale->status === SaleStatus::Draft) {
            throw new BusinessRuleException(__('A portal is available for reserved and sold cars only.'));
        }

        return DB::transaction(function () use ($sale, $days): array {
            $this->revoke($sale);
            $plain = PortalLink::PREFIX.Str::random(40);
            $link = PortalLink::query()->create([
                'sale_id' => $sale->getKey(),
                'token_prefix' => substr($plain, 0, 12),
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addDays($days),
            ]);

            return [$link, route('portal.show', ['token' => $plain])];
        });
    }

    public function revoke(Sale $sale): int
    {
        return PortalLink::query()->where('sale_id', $sale->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * An e-mail with the link to the buyer, as a draft in the inbox (sent on click).
     */
    public function draftEmail(Sale $sale, string $url): EmailMessage
    {
        $sale->loadMissing(['buyer', 'stockCycle.vehicle']);
        $mailbox = SaveEmailDraft::sendingMailbox() ?? throw new BusinessRuleException(__('Set up a mailbox with SMTP first (Settings → Mailboxes).'));
        $email = $sale->buyer->email ?? throw new BusinessRuleException(__('The buyer has no e-mail address.'));
        $locale = $sale->buyer->locale ?? $this->context->tenant()->default_locale ?? 'de';

        return $this->drafts->compose($mailbox, [
            'to' => $email,
            'subject' => __('Your :vehicle: documents and status', ['vehicle' => $sale->stockCycle->vehicle->displayName()], $locale),
            'body' => __("Good day :name\n\nIn your personal customer area you find the status of your purchase, your contract, invoices and warranty, and you can send us documents:\n\n:url\n\nThe link is personal; please do not pass it on.\n\nKind regards\n:dealer", [
                'name' => $sale->buyer->displayName(),
                'url' => $url,
                'dealer' => $this->context->tenant()->name ?? '',
            ], $locale),
        ], $sale->stock_cycle_id, $sale->buyer_party_id);
    }
}
