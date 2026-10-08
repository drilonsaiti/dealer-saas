<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\FolderGroup;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentFileName;
use App\Domain\Documents\Support\PdfRenderer;
use App\Domain\Reporting\CalculateMargin;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The whole vehicle file as one ZIP (acceptance test 12): the six folders with every
 * document version, named {Stammnummer}_{date}_{type}_{LANG}_{status}, plus an overview
 * PDF with vehicle, purchase, sale, costs, margin, checklist and history.
 * Without Gotenberg the overview is included as HTML instead.
 */
class ExportVehicleFile
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PdfRenderer $pdf,
        private readonly CalculateMargin $margin,
        private readonly RequiredDocumentsChecklist $checklist,
    ) {}

    /**
     * @return string path of the ZIP in the temp directory (the caller deletes it)
     */
    public function __invoke(StockCycle $cycle, bool $includeSensitive, ?string $locale = null): string
    {
        $locale ??= $this->context->tenant()->default_locale ?? 'de';
        $previousLocale = App::getLocale();
        App::setLocale($locale);

        try {
            return $this->build($cycle->load(['vehicle', 'purchase.seller', 'activeSale.buyer', 'activeSale.items', 'activeSale.tradeIn', 'costs.category', 'commitments']), $includeSensitive, $locale);
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function fileName(StockCycle $cycle): string
    {
        return Str::slug(($cycle->number ?? 'akte').'-'.$cycle->vehicle->displayName()).'.zip';
    }

    private function build(StockCycle $cycle, bool $includeSensitive, string $locale): string
    {
        $documents = Document::query()
            ->linkedTo($cycle)
            ->visibleTo($includeSensitive)
            ->with(['category', 'versions'])
            ->orderBy('document_on')
            ->get();

        $zipPath = tempnam(sys_get_temp_dir(), 'akte').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create ZIP file.');
        }

        $prefix = $cycle->vehicle->stammnummer ?? Str::slug($cycle->number ?? 'akte');
        $used = [];

        foreach (FolderGroup::cases() as $folder) {
            $zip->addEmptyDir($folder->folderName($locale));
        }

        foreach ($documents as $document) {
            foreach ($document->versions as $version) {
                $name = DocumentFileName::make($document, $version, $cycle, DocumentFileName::sequenceIn($documents, $document), $locale);
                $name = $this->unique($name, $used);
                $zip->addFromString($document->category->folder_group->folderName($locale).'/'.$name, $version->contents());
            }
        }

        $html = view('documents.vehicle-file-overview', [
            'cycle' => $cycle,
            'tenant' => $this->context->tenant(),
            'margin' => $cycle->purchase === null ? null : ($this->margin)($cycle),
            'checklist' => ($this->checklist)($cycle),
            'documents' => $documents,
            'history' => $cycle->statusHistory()->with('user')->get(),
        ])->render();

        $overview = $prefix.'_'.__('Overview');

        try {
            $zip->addFromString($overview.'.pdf', $this->pdf->fromHtml($html));
        } catch (Throwable $e) {
            Log::warning('Overview PDF not rendered, adding HTML instead', ['error' => $e->getMessage()]);
            $zip->addFromString($overview.'.html', $html);
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * @param  array<string, true>  $used
     */
    private function unique(string $name, array &$used): string
    {
        $candidate = $name;
        $i = 2;

        while (isset($used[$candidate])) {
            $candidate = preg_replace('/(\.[^.]+)$/', "_{$i}$1", $name) ?? $name.$i;
            $i++;
        }

        $used[$candidate] = true;

        return $candidate;
    }
}
