<?php

namespace App\Domain\Warranty\Actions;

use App\Domain\Documents\Actions\AddDocumentVersion;
use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Purchasing\Actions\ConfirmCost;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Support\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The provider confirmed the policy: number recorded, premium confirmed as cost, certificate
 * filed. A later change (e.g. coverage raised to CHF 15'000) is a new version of the same
 * certificate, never a second document.
 */
class RegisterWarranty
{
    public function __construct(
        private readonly StoreDocument $store,
        private readonly AddDocumentVersion $addVersion,
        private readonly ConfirmCost $confirmCost,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  coverage_limit_rp, km_limit, deductible_rp
     */
    public function __invoke(Warranty $warranty, string $policyNumber, UploadedFile|string|null $certificate = null, ?string $fileName = null, array $changes = []): Warranty
    {
        if (in_array($warranty->status, [WarrantyStatus::Cancelled, WarrantyStatus::Expired], true)) {
            throw new BusinessRuleException(__('This warranty is no longer valid.'));
        }

        if (trim($policyNumber) === '') {
            throw new BusinessRuleException(__('Enter the policy number.'));
        }

        return DB::transaction(function () use ($warranty, $policyNumber, $certificate, $fileName, $changes): Warranty {
            $warranty->forceFill(['policy_number' => trim($policyNumber)])
                ->fill(array_intersect_key($changes, array_flip(['coverage_limit_rp', 'km_limit', 'deductible_rp'])))
                ->save();

            if ($warranty->cost !== null && ! $warranty->cost->isConfirmed()) {
                ($this->confirmCost)($warranty->cost);
            }

            if ($certificate !== null) {
                $this->file($warranty, $certificate, $fileName);
            }

            return $warranty->refresh();
        });
    }

    private function file(Warranty $warranty, UploadedFile|string $file, ?string $fileName): void
    {
        $name = $fileName ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file));

        if ($warranty->certificate !== null) {
            ($this->addVersion)($warranty->certificate, $file, $name);

            return;
        }

        $category = DocumentCategory::query()->where('key', 'warranty_policy')->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', 'warranty_policy')->firstOrFail();
        }

        $document = ($this->store)($file, $category, [
            'title' => $category->name.' '.$warranty->policy_number,
            'document_on' => now()->toDateString(),
            'original_name' => $name,
        ], $warranty->sale === null ? [$warranty->stockCycle] : [$warranty->stockCycle, $warranty->sale]);

        $warranty->forceFill(['certificate_document_id' => $document->getKey()])->save();
    }
}
