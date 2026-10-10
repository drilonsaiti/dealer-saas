<?php

namespace App\Domain\Inbox\Actions;

use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Vehicles\Models\StockCycle;

/**
 * A person assigns (or corrects) contact and vehicle file of an e-mail; its attachments move
 * to the chosen file.
 */
class AssignEmail
{
    public function __invoke(EmailMessage $message, ?string $partyId, ?string $stockCycleId): EmailMessage
    {
        $previous = $message->stock_cycle_id;
        $message->forceFill(['party_id' => $partyId, 'stock_cycle_id' => $stockCycleId, 'matched_by' => 'manual'])->save();

        if ($previous !== $stockCycleId) {
            $documentIds = DocumentLink::query()->where('linkable_type', $message->getMorphClass())->where('linkable_id', $message->getKey())->pluck('document_id');
            $cycleMorph = (new StockCycle)->getMorphClass();

            if ($previous !== null) {
                DocumentLink::query()->whereIn('document_id', $documentIds)->where('linkable_type', $cycleMorph)->where('linkable_id', $previous)->delete();
            }

            if ($stockCycleId !== null) {
                foreach ($documentIds as $documentId) {
                    DocumentLink::query()->firstOrCreate(['document_id' => $documentId, 'linkable_type' => $cycleMorph, 'linkable_id' => $stockCycleId]);
                }
            }
        }

        return $message;
    }

    public function handled(EmailMessage $message, bool $handled = true): EmailMessage
    {
        $message->forceFill(['handled_at' => $handled ? now() : null, 'handled_by' => $handled ? auth()->id() : null, 'read_at' => $message->read_at ?? now()])->save();

        return $message;
    }
}
