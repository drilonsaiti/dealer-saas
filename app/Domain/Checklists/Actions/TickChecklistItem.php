<?php

namespace App\Domain\Checklists\Actions;

use App\Domain\Checklists\Models\ChecklistItem;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Auth;

/**
 * A user ticks (or unticks) an item by hand, optionally with an evidence document. Items
 * with a rule tick themselves; a user may still confirm one by hand (e.g. a paper contract).
 */
class TickChecklistItem
{
    public function __invoke(ChecklistItem $item, bool $done = true, ?string $evidenceDocumentId = null): ChecklistItem
    {
        if (! $item->applicable) {
            throw new BusinessRuleException(__('This item does not apply to this sale.'));
        }

        // Rules about money, promises and the warranty answer from the records only; a
        // document rule may be confirmed by hand (e.g. a contract signed on paper).
        if ($item->auto_rule !== null && ! str_starts_with($item->auto_rule, 'document:')) {
            throw new BusinessRuleException(__('This item is ticked automatically from the records.'));
        }

        if (! $done && $item->auto) {
            throw new BusinessRuleException(__('This item is ticked automatically from the records.'));
        }

        $item->forceFill([
            'done_at' => $done ? now() : null,
            'done_by' => $done ? Auth::id() : null,
            'auto' => false,
            'evidence_document_id' => $done ? ($evidenceDocumentId ?? $item->evidence_document_id) : null,
        ])->save();

        return $item;
    }
}
