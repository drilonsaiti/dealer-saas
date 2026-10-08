<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Support\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Replaces a document's file by adding a new version; the old versions stay.
 */
class AddDocumentVersion
{
    public function __construct(private readonly StoreDocument $store) {}

    public function __invoke(Document $document, UploadedFile|string $file, ?string $originalName = null): DocumentVersion
    {
        if ($document->isLocked()) {
            throw new BusinessRuleException(__('This document is locked and cannot be replaced.'));
        }

        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if ($path === false || ! is_file($path)) {
            throw new BusinessRuleException(__('The file could not be read.'));
        }

        $sha = hash_file('sha256', $path);
        $this->store->refuseExactDuplicate($sha);
        $originalName ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        $disk = (string) config('dealer.documents.disk');
        $stored = null;

        try {
            $version = DB::transaction(function () use ($document, $path, $sha, $originalName, $disk, &$stored): DocumentVersion {
                $locked = Document::query()->lockForUpdate()->findOrFail($document->getKey());
                $next = (int) $locked->versions()->max('version_no') + 1;

                $stored = $this->store->storeFile($disk, $locked, $next, $path, $sha, $originalName);
                $version = $this->store->createVersion($locked, $next, $disk, $stored, $path, $sha, $originalName, $locked->category, $locked->document_on, $locked->source);
                $locked->forceFill(['current_version_id' => $version->getKey()])->save();

                return $version;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                Storage::disk($disk)->delete($stored);
            }

            throw $e;
        }

        $document->refresh();
        $this->store->queueOcr($version);

        return $version;
    }
}
