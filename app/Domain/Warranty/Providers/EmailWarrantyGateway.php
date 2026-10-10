<?php

namespace App\Domain\Warranty\Providers;

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Support\PdfRenderer;
use App\Domain\Inbox\Actions\SaveEmailDraft;
use App\Domain\Inbox\Models\Mailbox;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Support\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Registration form or claim report as PDF (filed in the vehicle file), prepared as an e-mail
 * draft to the provider in the inbox, with the claim's photos and invoices attached. A person
 * checks and sends it.
 */
class EmailWarrantyGateway implements WarrantyProviderGateway
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly StoreDocument $store,
        private readonly SaveEmailDraft $drafts,
        private readonly TenantContext $context,
    ) {}

    public function submit(Warranty $warranty): string
    {
        $warranty->loadMissing(['product.provider', 'stockCycle.vehicle', 'sale.buyer']);
        [$address, $mailbox] = $this->route($warranty);
        $vehicle = $warranty->stockCycle->vehicle;

        return DB::transaction(function () use ($warranty, $address, $mailbox, $vehicle): string {
            $document = $this->file('warranty.registration', ['warranty' => $warranty, 'tenant' => $this->context->tenant()],
                __('Warranty registration :vehicle', ['vehicle' => $vehicle->displayName()]), 'garantie-anmeldung.pdf', [$warranty->stockCycle, $warranty]);

            $draft = $this->drafts->compose($mailbox, [
                'to' => $address,
                'subject' => __('Warranty registration: :product – :vehicle (VIN :vin)', ['product' => $warranty->product->label(), 'vehicle' => $vehicle->displayName(), 'vin' => $vehicle->vin ?? '–']),
                'body' => __("Good day\n\nPlease find attached the registration of a warranty for :vehicle (VIN :vin), sold to :buyer, starting :start.\n\nPlease send us the policy number and the certificate.\n\nKind regards\n:dealer", [
                    'vehicle' => $vehicle->displayName(),
                    'vin' => $vehicle->vin ?? '–',
                    'buyer' => $warranty->sale?->buyer->displayName() ?? '–',
                    'start' => $warranty->starts_on?->format('d.m.Y') ?? __('at handover'),
                    'dealer' => $this->context->tenant()->name,
                ]),
            ], $warranty->stock_cycle_id, $warranty->product->provider_party_id, [$document->getKey()]);

            $warranty->forceFill(['submitted_at' => now(), 'submission_email_id' => $draft->getKey()])->save();

            return __('The registration is ready as a draft in the inbox. Check it and click "Send".');
        });
    }

    public function report(WarrantyClaim $claim): string
    {
        $claim->loadMissing(['warranty.product.provider', 'warranty.stockCycle.vehicle', 'warranty.sale.buyer', 'workshop']);
        $warranty = $claim->warranty;
        [$address, $mailbox] = $this->route($warranty);
        $vehicle = $warranty->stockCycle->vehicle;

        return DB::transaction(function () use ($claim, $warranty, $address, $mailbox, $vehicle): string {
            $report = $this->file('warranty.claim', ['claim' => $claim, 'warranty' => $warranty, 'tenant' => $this->context->tenant()],
                __('Warranty claim :date', ['date' => $claim->occurred_on->format('d.m.Y')]), 'garantiefall.pdf', [$warranty->stockCycle, $claim]);

            $evidence = Document::query()->linkedTo($claim)->where('documents.id', '!=', $report->getKey())->pluck('documents.id')->all();

            $draft = $this->drafts->compose($mailbox, [
                'to' => $address,
                'subject' => __('Warranty claim: policy :policy – :vehicle', ['policy' => $warranty->policy_number ?? '–', 'vehicle' => $vehicle->displayName()]),
                'body' => __("Good day\n\nWe report a warranty claim for policy :policy (:vehicle, VIN :vin): :description\n\nDamage date :date, mileage :km km. The report and the documents are attached.\n\nPlease confirm the cover.\n\nKind regards\n:dealer", [
                    'policy' => $warranty->policy_number ?? '–',
                    'vehicle' => $vehicle->displayName(),
                    'vin' => $vehicle->vin ?? '–',
                    'description' => $claim->description,
                    'date' => $claim->occurred_on->format('d.m.Y'),
                    'km' => number_format($claim->mileage, 0, '.', "'"),
                    'dealer' => $this->context->tenant()->name,
                ]),
            ], $warranty->stock_cycle_id, $warranty->product->provider_party_id, [$report->getKey(), ...$evidence]);

            $claim->forceFill(['reported_at' => now(), 'report_email_id' => $draft->getKey()])->save();

            return __('The claim report is ready as a draft in the inbox. Check it and click "Send".');
        });
    }

    /**
     * @return array{0: string, 1: Mailbox}
     */
    private function route(Warranty $warranty): array
    {
        $address = $warranty->product->submissionAddress();

        if ($address === null) {
            throw new BusinessRuleException(__('Enter the e-mail address of the provider in the warranty product.'));
        }

        $mailbox = SaveEmailDraft::sendingMailbox()
            ?? throw new BusinessRuleException(__('Set up a mailbox with SMTP first (Settings → Mailboxes).'));

        return [$address, $mailbox];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<Model>  $links
     */
    private function file(string $view, array $data, string $title, string $fileName, array $links): Document
    {
        if (! $this->pdf->isAvailable()) {
            throw new BusinessRuleException(__('PDFs cannot be created: the PDF service (Gotenberg) is not configured.'));
        }

        $category = DocumentCategory::query()->where('key', 'warranty_submission')->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', 'warranty_submission')->firstOrFail();
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'warranty');
        file_put_contents($path, $this->pdf->fromHtml(view('documents.'.$view, $data)->render()));

        try {
            $document = ($this->store)($path, $category, [
                'title' => $title,
                'document_on' => now()->toDateString(),
                'source' => DocumentSource::Generated,
                'original_name' => $fileName,
            ], $links);
        } finally {
            @unlink($path);
        }

        $document->currentVersion?->forceFill(['locked_at' => now()])->save();

        return $document;
    }
}
