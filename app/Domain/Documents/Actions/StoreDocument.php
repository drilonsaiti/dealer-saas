<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Documents\Jobs\RunOcr;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Support\DuplicateDocument;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores a new document (first version) and links it to its records.
 *
 * - Exact duplicate (same SHA-256 for this dealer): refused, pointing to the existing one.
 * - Near duplicate (same category and date in the same vehicle file, e.g. the same contract
 *   scanned twice): stored, but flagged "possible duplicate" so the user can merge them.
 * - Files live under tenants/{tenant_id}/documents/{document_id}/; OCR runs in the queue.
 */
class StoreDocument
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{title?: string|null, document_on?: string|null, locale?: string|null, source?: DocumentSource|string, legacy_ref?: string|null, original_name?: string|null}  $attributes
     * @param  list<Model>  $links
     */
    public function __invoke(UploadedFile|string $file, DocumentCategory $category, array $attributes = [], array $links = []): Document
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if ($path === false || ! is_file($path)) {
            throw new BusinessRuleException(__('The file could not be read.'));
        }

        $sha = hash_file('sha256', $path);
        $this->refuseExactDuplicate($sha);

        $originalName = $attributes['original_name'] ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path));
        $documentOn = filled($attributes['document_on'] ?? null) ? Carbon::parse($attributes['document_on']) : null;
        $source = $attributes['source'] ?? DocumentSource::Upload;
        $disk = (string) config('dealer.documents.disk');
        $stored = null;

        try {
            $document = DB::transaction(function () use ($path, $sha, $originalName, $documentOn, $source, $category, $attributes, $links, $disk, &$stored): Document {
                $document = Document::create([
                    'category_id' => $category->getKey(),
                    'title' => filled($attributes['title'] ?? null) ? $attributes['title'] : trim($category->name.' '.($documentOn?->format('d.m.Y') ?? '')),
                    'document_on' => $documentOn,
                    'locale' => $attributes['locale'] ?? null,
                    'source' => $source,
                    'legacy_ref' => $attributes['legacy_ref'] ?? null,
                ]);

                $stored = $this->storeFile($disk, $document, 1, $path, $sha, $originalName);
                $version = $this->createVersion($document, 1, $disk, $stored, $path, $sha, $originalName, $category, $documentOn, $source);
                $document->forceFill(['current_version_id' => $version->getKey()])->save();

                foreach ($links as $record) {
                    DocumentLink::create([
                        'document_id' => $document->getKey(),
                        'linkable_type' => $record->getMorphClass(),
                        'linkable_id' => $record->getKey(),
                    ]);
                }

                $this->flagNearDuplicate($document, $links);

                return $document;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                Storage::disk($disk)->delete($stored);
            }

            throw $e;
        }

        $this->queueOcr($document->currentVersion);

        return $document;
    }

    public function refuseExactDuplicate(string $sha): void
    {
        $existing = DocumentVersion::query()->with('document')->where('sha256', $sha)->first();

        if ($existing !== null) {
            throw DuplicateDocument::of($existing->document);
        }
    }

    public function storeFile(string $disk, Document $document, int $versionNo, string $sourcePath, string $sha, string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin');
        $target = sprintf('tenants/%s/documents/%s/v%d-%s.%s', $this->context->id(), $document->getKey(), $versionNo, substr($sha, 0, 12), $extension);

        $stream = fopen($sourcePath, 'rb');

        try {
            Storage::disk($disk)->put($target, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $target;
    }

    public function createVersion(Document $document, int $versionNo, string $disk, string $storedPath, string $sourcePath, string $sha, string $originalName, DocumentCategory $category, ?Carbon $documentOn, DocumentSource|string $source): DocumentVersion
    {
        $mime = (string) (mime_content_type($sourcePath) ?: 'application/octet-stream');
        $source = $source instanceof DocumentSource ? $source : DocumentSource::from($source);

        return DocumentVersion::create([
            'document_id' => $document->getKey(),
            'version_no' => $versionNo,
            'disk' => $disk,
            'path' => $storedPath,
            'original_name' => Str::limit($originalName, 250, ''),
            'mime' => $mime,
            'size' => (int) filesize($sourcePath),
            'sha256' => $sha,
            // Generated documents carry their structured data; they are never OCR'd.
            'ocr_status' => $source === DocumentSource::Generated || ! self::isOcrCandidate($mime) ? OcrStatus::NotNeeded : OcrStatus::Pending,
            'retain_until' => ($documentOn ?? Carbon::today())->copy()->addYears($category->retention_years)->toDateString(),
            'created_by' => auth()->id(),
        ]);
    }

    public function queueOcr(?DocumentVersion $version): void
    {
        if ($version !== null && $version->ocr_status === OcrStatus::Pending) {
            RunOcr::dispatch($version->getKey())->afterCommit();
        }
    }

    public static function isOcrCandidate(string $mime): bool
    {
        return $mime === 'application/pdf' || in_array($mime, ['image/jpeg', 'image/png', 'image/tiff', 'image/webp', 'image/bmp'], true);
    }

    /**
     * @param  list<Model>  $links
     */
    private function flagNearDuplicate(Document $document, array $links): void
    {
        $cycle = collect($links)->first(fn (Model $record): bool => $record instanceof StockCycle);

        if (! $cycle instanceof StockCycle || $document->document_on === null) {
            return;
        }

        $similar = Document::query()
            ->whereKeyNot($document->getKey())
            ->where('category_id', $document->category_id)
            ->whereDate('document_on', $document->document_on)
            ->linkedTo($cycle)
            ->oldest()
            ->first();

        if ($similar !== null) {
            $document->forceFill(['possible_duplicate_of_id' => $similar->getKey()])->save();
        }
    }
}
