<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Generation\ContractData;
use App\Domain\Documents\Generation\DocumentRenderer;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Actions\IssueNumber;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Contract wizard, steps 1–2: check the prefilled data (preview), then finalise: number,
 * snapshot, PDF, SHA-256, stored as a locked version in the vehicle file. Finalising again
 * after a change adds a new version with the same contract number; a signed contract is final.
 */
class GenerateContract
{
    public function __construct(
        private readonly ContractData $data,
        private readonly DocumentRenderer $renderer,
        private readonly InstallDefaultTemplates $templates,
        private readonly IssueNumber $issueNumber,
        private readonly StoreDocument $store,
        private readonly AddDocumentVersion $addVersion,
    ) {}

    /**
     * HTML of the contract as it would be finalised now (the number shows as a placeholder
     * until the first finalisation).
     */
    public function preview(Sale|Purchase $subject, string $locale, ?string $remarks): string
    {
        $type = self::typeFor($subject);
        $this->guard($subject, $locale);
        $existing = $this->existing($type, $subject);

        return $this->renderer->html($this->snapshot($subject, $this->templates->active($type), $locale, $existing->number ?? '—', $remarks));
    }

    public function __invoke(Sale|Purchase $subject, string $locale, ?string $remarks): Document
    {
        $type = self::typeFor($subject);
        $this->guard($subject, $locale);

        return DB::transaction(function () use ($type, $subject, $locale, $remarks): Document {
            $existing = $this->existing($type, $subject);

            if ($existing !== null && $existing->status->isLocked()) {
                throw new BusinessRuleException(__('This contract is already signed and cannot be changed.'));
            }

            if ($existing?->status === DocumentStatus::OutForSignature) {
                throw new BusinessRuleException(__('This contract is out for signature. Withdraw the signing first to change it.'));
            }

            $template = $this->templates->active($type);
            $number = $existing->number ?? ($this->issueNumber)($type->numberSequence());
            $snapshot = $this->snapshot($subject, $template, $locale, $number, $remarks);
            $pdf = $this->renderer->pdf($snapshot);
            $sha = hash('sha256', $pdf);

            // Nothing changed since the last finalisation: keep that version.
            if ($existing !== null && $existing->versions()->where('sha256', $sha)->exists()) {
                return $existing;
            }

            $file = tempnam(sys_get_temp_dir(), 'contract');
            file_put_contents($file, $pdf);
            $name = $this->fileName($type, $subject, $locale);

            try {
                if ($existing === null) {
                    $document = ($this->store)($file, $this->category($type), [
                        'title' => $type->labelIn($locale).' '.$number,
                        'document_on' => now()->toDateString(),
                        'locale' => $locale,
                        'source' => DocumentSource::Generated,
                        'original_name' => $name,
                    ], $this->links($subject));
                    $version = $document->currentVersion;
                } else {
                    $document = $existing;
                    $version = ($this->addVersion)($existing, $file, $name);
                }
            } finally {
                @unlink($file);
            }

            /** @var DocumentVersion $version */
            $version->forceFill([
                'data_snapshot' => $snapshot,
                'template_version_id' => $template->getKey(),
                'locked_at' => now(),
            ])->save();

            $document->forceFill([
                'type_key' => $type->value,
                'number' => $number,
                'locale' => $locale,
                'status' => DocumentStatus::Final,
            ])->save();

            return $document->refresh();
        });
    }

    /**
     * The current (not voided) contract of this sale or purchase, if one was finalised.
     */
    public function existing(TemplateType $type, Sale|Purchase $subject): ?Document
    {
        return Document::query()
            ->where('type_key', $type->value)
            ->linkedTo($subject)
            ->whereNotIn('status', [DocumentStatus::Void->value, DocumentStatus::Superseded->value])
            ->latest()
            ->first();
    }

    public static function typeFor(Sale|Purchase $subject): TemplateType
    {
        return $subject instanceof Sale ? TemplateType::SalesContract : TemplateType::PurchaseContract;
    }

    /**
     * What the contract is missing; shown in the wizard, does not block finalising.
     *
     * @return list<string>
     */
    public function warnings(Sale|Purchase $subject): array
    {
        $party = $subject instanceof Sale ? $subject->buyer : $subject->seller;
        $vehicle = $subject->stockCycle->vehicle;

        return array_values(array_filter([
            $party === null ? __('No seller is chosen.') : null,
            $party !== null && (blank($party->street) || blank($party->city)) ? __(':name has no complete address.', ['name' => $party->displayName()]) : null,
            blank($vehicle->vin) ? __('The VIN is missing.') : null,
            blank($vehicle->stammnummer) ? __('The Stammnummer is missing.') : null,
            $vehicle->first_registration_on === null ? __('The first registration date is missing.') : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Sale|Purchase $subject, DocumentTemplate $template, string $locale, string $number, ?string $remarks): array
    {
        return $subject instanceof Sale
            ? $this->data->forSale($subject, $template, $locale, $number, $remarks)
            : $this->data->forPurchase($subject, $template, $locale, $number, $remarks);
    }

    private function guard(Sale|Purchase $subject, string $locale): void
    {
        if (! in_array($locale, (array) config('dealer.locales'), true)) {
            throw new BusinessRuleException(__('This language is not available for documents.'));
        }

        if ($subject instanceof Sale && ! in_array($subject->status, [SaleStatus::Reserved, SaleStatus::Contracted, SaleStatus::Invoiced], true)) {
            throw new BusinessRuleException(__('A sales contract can only be made for a reserved or sold vehicle.'));
        }
    }

    private function category(TemplateType $type): DocumentCategory
    {
        return DocumentCategory::query()->where('key', $type->categoryKey())->firstOrFail();
    }

    /**
     * @return list<Model>
     */
    private function links(Sale|Purchase $subject): array
    {
        /** @var StockCycle $cycle */
        $cycle = $subject->stockCycle;
        $party = $subject instanceof Sale ? $subject->buyer : $subject->seller;

        $links = [$cycle, $subject];

        if ($party !== null) {
            $links[] = $party;
        }

        return $links;
    }

    /**
     * {Stammnummer}_{date}_{Type}_{LANG}.pdf, as in the vehicle file export (concept 8.1).
     */
    private function fileName(TemplateType $type, Sale|Purchase $subject, string $locale): string
    {
        $stammnummer = $subject->stockCycle->vehicle->stammnummer ?? 'OHNE-STAMMNUMMER';
        $label = str_replace(' ', '-', $type->labelIn($locale));

        return sprintf('%s_%s_%s_%s.pdf', $stammnummer, now()->format('Y-m-d'), $label, strtoupper($locale));
    }
}
