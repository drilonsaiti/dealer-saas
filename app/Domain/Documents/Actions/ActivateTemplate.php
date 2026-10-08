<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\TemplateStatus;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Makes a draft the version new documents use; the previous active version is retired.
 * Documents made earlier keep the version they were made with.
 */
class ActivateTemplate
{
    public function __invoke(DocumentTemplate $draft): DocumentTemplate
    {
        if (! $draft->isEditable()) {
            throw new BusinessRuleException(__('Only a draft can be activated.'));
        }

        $missing = $draft->missingLocales();

        if ($missing !== []) {
            throw new BusinessRuleException(__('The clauses are missing in: :languages. A template is used for customers in all four languages.', [
                'languages' => collect($missing)->map(fn (string $locale): string => (string) (config('dealer.locale_names')[$locale] ?? $locale))->implode(', '),
            ]));
        }

        return DB::transaction(function () use ($draft): DocumentTemplate {
            DocumentTemplate::query()
                ->active()
                ->where('type_key', $draft->type_key->value)
                ->lockForUpdate()
                ->get()
                ->each(fn (DocumentTemplate $active) => $active->forceFill(['status' => TemplateStatus::Retired])->save());

            $draft->forceFill(['status' => TemplateStatus::Active, 'valid_from' => now()->toDateString()])->save();

            return $draft;
        });
    }
}
