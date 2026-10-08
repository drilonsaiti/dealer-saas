<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a wrongly uploaded document with all its files. Locked documents (signed, or in a
 * closed vehicle file) are never deleted. The audit log keeps who deleted what.
 */
class DeleteDocument
{
    public function __invoke(Document $document): void
    {
        if ($document->isLocked()) {
            throw new BusinessRuleException(__('This document is locked and cannot be deleted.'));
        }

        $files = $document->versions()->get(['disk', 'path']);

        DB::transaction(function () use ($document): void {
            Document::query()->where('possible_duplicate_of_id', $document->getKey())->update(['possible_duplicate_of_id' => null]);
            $document->forceFill(['current_version_id' => null])->save();
            $document->delete();
        });

        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }
    }
}
