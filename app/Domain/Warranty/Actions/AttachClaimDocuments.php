<?php

namespace App\Domain\Warranty\Actions;

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Warranty\Models\WarrantyClaim;
use Illuminate\Http\UploadedFile;

/**
 * Photos, diagnosis and workshop invoices of a warranty claim: filed in the vehicle file and
 * linked to the claim, so they go to the provider with the claim report.
 */
class AttachClaimDocuments
{
    public function __construct(private readonly StoreDocument $store) {}

    /**
     * @param  list<array{0: UploadedFile|string, 1: string}>  $files  [file, original name]
     * @return list<Document>
     */
    public function __invoke(WarrantyClaim $claim, array $files): array
    {
        $category = DocumentCategory::query()->where('key', 'warranty_claim')->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', 'warranty_claim')->firstOrFail();
        }

        $documents = [];

        foreach ($files as [$file, $name]) {
            $documents[] = ($this->store)($file, $category, [
                'title' => pathinfo($name, PATHINFO_FILENAME),
                'original_name' => $name,
                'document_on' => $claim->occurred_on->toDateString(),
            ], [$claim->warranty->stockCycle, $claim]);
        }

        return $documents;
    }
}
