<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\TemplateStatus;
use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Support\DefaultTemplates;

/**
 * Gives the current dealer version 1 of every document template (system clauses in all four
 * languages), for types that have no version yet. Safe to run again.
 */
class InstallDefaultTemplates
{
    public function __invoke(): int
    {
        $existing = DocumentTemplate::query()->distinct()->pluck('type_key')->map(fn (TemplateType|string $type): string => $type instanceof TemplateType ? $type->value : $type)->all();
        $created = 0;

        foreach (TemplateType::cases() as $type) {
            if (in_array($type->value, $existing, true)) {
                continue;
            }

            $template = DocumentTemplate::create([
                'type_key' => $type,
                'version' => 1,
                'valid_from' => now()->toDateString(),
                'clauses' => DefaultTemplates::clauses($type),
            ]);
            $template->forceFill(['status' => TemplateStatus::Active])->save();
            $created++;
        }

        return $created;
    }

    /**
     * The version new documents of this type are made with.
     */
    public function active(TemplateType $type): DocumentTemplate
    {
        $template = DocumentTemplate::query()->active()->where('type_key', $type->value)->first();

        if ($template === null) {
            $this();
            $template = DocumentTemplate::query()->active()->where('type_key', $type->value)->firstOrFail();
        }

        return $template;
    }
}
