<?php

namespace App\Domain\Checklists\Actions;

use App\Domain\Checklists\Enums\ChecklistKind;
use App\Domain\Checklists\Models\Checklist;
use App\Domain\Checklists\Models\ChecklistItem;
use App\Domain\Checklists\Models\ChecklistTemplate;
use App\Domain\Checklists\Models\ChecklistTemplateItem;
use App\Domain\Checklists\Support\ChecklistRules;
use App\Domain\Financing\Models\Financing;
use App\Domain\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Starts the checklist of a sale (or financing) from the active template the first time
 * and updates its automatic items from the records every time. A financing uses its
 * partner's own template when there is one, otherwise the default.
 */
class SyncChecklist
{
    public function __construct(private readonly ChecklistRules $rules) {}

    public function handover(Sale $sale): Checklist
    {
        return $this->sync($sale, ChecklistKind::Handover, null);
    }

    public function partner(Financing $financing): Checklist
    {
        return $this->sync($financing->sale, ChecklistKind::FinancingPartner, $financing);
    }

    private function sync(Sale $sale, ChecklistKind $kind, ?Financing $financing): Checklist
    {
        return DB::transaction(function () use ($sale, $kind, $financing): Checklist {
            $checklist = Checklist::query()
                ->where('sale_id', $sale->getKey())
                ->where('kind', $kind->value)
                ->where('financing_id', $financing?->getKey())
                ->first() ?? $this->start($sale, $kind, $financing);

            foreach ($checklist->items as $item) {
                $this->evaluate($item, $sale, $financing);
            }

            return $checklist->load('items');
        });
    }

    private function start(Sale $sale, ChecklistKind $kind, ?Financing $financing): Checklist
    {
        app(InstallDefaultChecklists::class)();

        $template = ChecklistTemplate::query()
            ->where('kind', $kind->value)
            ->where('is_active', true)
            ->when($financing !== null, fn ($q) => $q->where(fn ($q) => $q->where('partner_party_id', $financing->partner_party_id)->orWhereNull('partner_party_id')))
            ->when($financing === null, fn ($q) => $q->whereNull('partner_party_id'))
            ->orderByRaw('partner_party_id is null')
            ->orderByDesc('version')
            ->with('items')
            ->firstOrFail();

        $checklist = Checklist::create([
            'template_id' => $template->getKey(),
            'kind' => $kind,
            'sale_id' => $sale->getKey(),
            'financing_id' => $financing?->getKey(),
        ]);

        foreach ($template->items as $item) {
            /** @var ChecklistTemplateItem $item */
            $checklist->items()->create([
                'key' => $item->key,
                'label' => $item->getTranslations('label'),
                'required' => $item->required,
                'auto_rule' => $item->auto_rule,
                'sort' => $item->sort,
            ]);
        }

        return $checklist->load('items');
    }

    private function evaluate(ChecklistItem $item, Sale $sale, ?Financing $financing): void
    {
        if ($item->auto_rule === null) {
            return;
        }

        $result = $this->rules->evaluate($item->auto_rule, $sale, $financing);
        $applicable = $result !== null;
        $done = $result === true;

        // A manual tick on an item with a rule stays; the rule only sets and clears its own ticks.
        if ($item->done_at !== null && ! $item->auto) {
            $item->forceFill(['applicable' => $applicable])->save();

            return;
        }

        $item->forceFill([
            'applicable' => $applicable,
            'done_at' => $done ? ($item->done_at ?? now()) : null,
            'done_by' => null,
            'auto' => $done,
        ])->save();
    }
}
