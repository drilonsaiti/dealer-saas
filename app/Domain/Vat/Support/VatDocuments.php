<?php

namespace App\Domain\Vat\Support;

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Vat\Models\VatPeriod;
use Illuminate\Http\UploadedFile;

/**
 * Files the outputs of a VAT period (report, detail, XML, ESTV confirmation) in the document
 * archive, linked to the period, locked like every generated document.
 */
class VatDocuments
{
    public function __construct(private readonly StoreDocument $store) {}

    public function store(VatPeriod $period, string $categoryKey, string $contents, string $fileName, string $title): Document
    {
        $file = tempnam(sys_get_temp_dir(), 'vat');
        file_put_contents($file, $contents);

        try {
            return $this->file($period, $categoryKey, $file, $fileName, $title, DocumentSource::Generated);
        } finally {
            @unlink($file);
        }
    }

    public function upload(VatPeriod $period, UploadedFile|string $file, string $fileName, string $title): Document
    {
        return $this->file($period, 'vat_confirmation', $file, $fileName, $title, DocumentSource::Upload);
    }

    private function file(VatPeriod $period, string $categoryKey, UploadedFile|string $file, string $fileName, string $title, DocumentSource $source): Document
    {
        $category = DocumentCategory::query()->where('key', $categoryKey)->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', $categoryKey)->firstOrFail();
        }

        $document = ($this->store)($file, $category, [
            'title' => $title,
            'document_on' => $period->ends_on->toDateString(),
            'source' => $source,
            'original_name' => $fileName,
        ], [$period]);

        if ($source === DocumentSource::Generated) {
            $document->currentVersion?->forceFill(['locked_at' => now()])->save();
        }

        return $document;
    }
}
