<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Changes to a template never touch a version in use: they go into a draft (a new version
 * number), which becomes the active one only when activated.
 */
class SaveTemplateDraft
{
    /**
     * @param  array<string, list<string>|array<int, string|null>>  $clauses  per locale
     * @param  array<string, string|null>|null  $footer  per locale
     */
    public function __invoke(TemplateType $type, array $clauses, ?array $footer, ?string $notes = null, ?DocumentTemplate $draft = null): DocumentTemplate
    {
        if ($draft !== null && ! $draft->isEditable()) {
            throw new BusinessRuleException(__('Only drafts can be changed. Create a new version instead.'));
        }

        $attributes = [
            'clauses' => self::clean($clauses),
            'footer' => array_filter((array) $footer, fn (mixed $text): bool => filled($text)) ?: null,
            'notes' => $notes,
        ];

        if ($draft !== null) {
            $draft->fill($attributes)->save();

            return $draft;
        }

        return DB::transaction(function () use ($type, $attributes): DocumentTemplate {
            $latest = DocumentTemplate::query()->where('type_key', $type->value)->lockForUpdate()->pluck('version')->max();

            return DocumentTemplate::create([...$attributes, 'type_key' => $type, 'version' => (int) $latest + 1]);
        });
    }

    /**
     * @param  array<string, array<int, string|null>>  $clauses
     * @return array<string, list<string>>
     */
    public static function clean(array $clauses): array
    {
        $clean = [];

        foreach ((array) config('dealer.locales') as $locale) {
            $clean[$locale] = array_values(array_filter(
                array_map(fn (mixed $clause): string => trim((string) $clause), (array) ($clauses[$locale] ?? [])),
                fn (string $clause): bool => $clause !== '',
            ));
        }

        return $clean;
    }
}
