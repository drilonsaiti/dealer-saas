<?php

namespace App\Domain\Checklists\Actions;

use App\Domain\Checklists\Enums\ChecklistKind;
use App\Domain\Checklists\Models\ChecklistTemplate;
use App\Domain\Checklists\Support\ChecklistRules;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saves a checklist template as a new version (the previous one is retired); checklists
 * already started keep the items they were started with.
 */
class SaveChecklistTemplate
{
    /**
     * @param  array{kind?: string|ChecklistKind, partner_party_id?: string|null, name: array<string, string>|string}  $data
     * @param  list<array{key?: string|null, label: array<string, string|null>, required?: bool, auto_rule?: string|null}>  $items
     */
    public function __invoke(?ChecklistTemplate $previous, array $data, array $items): ChecklistTemplate
    {
        if ($items === []) {
            throw new BusinessRuleException(__('A checklist needs at least one item.'));
        }

        foreach ($items as $item) {
            if (blank($item['label']['de'] ?? null) && blank($item['label']['en'] ?? null)) {
                throw new BusinessRuleException(__('Every item needs a text.'));
            }

            if (filled($item['auto_rule'] ?? null) && ! array_key_exists($item['auto_rule'], ChecklistRules::options())) {
                throw new BusinessRuleException(__('Unknown automatic rule ":rule".', ['rule' => $item['auto_rule']]));
            }
        }

        return DB::transaction(function () use ($previous, $data, $items): ChecklistTemplate {
            $kind = $previous !== null ? $previous->kind : ChecklistKind::from((string) ($data['kind'] instanceof ChecklistKind ? $data['kind']->value : $data['kind']));
            $partner = $previous !== null ? $previous->partner_party_id : ($data['partner_party_id'] ?? null);
            $previous?->forceFill(['is_active' => false])->save();

            $template = ChecklistTemplate::create([
                'kind' => $kind,
                'partner_party_id' => $partner,
                'name' => self::languages($data['name']),
                'version' => $previous === null ? 1 : $previous->version + 1,
                'is_active' => true,
            ]);

            foreach ($items as $i => $item) {
                $label = self::languages($item['label']);
                $template->items()->create([
                    'key' => filled($item['key'] ?? null) ? $item['key'] : Str::slug((string) ($label['en'] ?? $label['de']), '_'),
                    'label' => $label,
                    'required' => (bool) ($item['required'] ?? true),
                    'auto_rule' => filled($item['auto_rule'] ?? null) ? $item['auto_rule'] : null,
                    'sort' => $i + 1,
                ]);
            }

            return $template->load('items');
        });
    }

    /**
     * @param  array<string, string|null>|string  $value
     * @return array<string, string>
     */
    private static function languages(array|string $value): array
    {
        $values = array_filter(is_array($value) ? $value : ['de' => $value], fn ($v): bool => filled($v));
        $first = (string) reset($values);

        return array_merge(array_fill_keys((array) config('dealer.locales'), $first), $values);
    }
}
