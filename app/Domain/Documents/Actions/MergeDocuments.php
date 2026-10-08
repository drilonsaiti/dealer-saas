<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentLink;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Near duplicates (the same contract scanned three times) become versions of one document.
 * The main document keeps its current version; the others' versions are appended to its
 * history and their links are carried over. Nothing is deleted from storage.
 */
class MergeDocuments
{
    public function __invoke(Document $main, Document $duplicate): Document
    {
        if ($main->is($duplicate)) {
            throw new BusinessRuleException(__('A document cannot be merged with itself.'));
        }

        if ($main->isLocked() || $duplicate->isLocked()) {
            throw new BusinessRuleException(__('This document is locked and cannot be replaced.'));
        }

        return DB::transaction(function () use ($main, $duplicate): Document {
            $next = (int) $main->versions()->max('version_no');

            foreach ($duplicate->versions()->reorder('version_no')->get() as $version) {
                $version->forceFill(['document_id' => $main->getKey(), 'version_no' => ++$next])->save();
            }

            foreach ($duplicate->links as $link) {
                DocumentLink::query()->firstOrCreate([
                    'document_id' => $main->getKey(),
                    'linkable_type' => $link->linkable_type,
                    'linkable_id' => $link->linkable_id,
                ]);
            }

            Document::query()->where('possible_duplicate_of_id', $duplicate->getKey())->update(['possible_duplicate_of_id' => null]);
            $duplicate->forceFill(['current_version_id' => null])->save();
            $duplicate->delete();

            if ($main->possible_duplicate_of_id === $duplicate->getKey()) {
                $main->forceFill(['possible_duplicate_of_id' => null])->save();
            }

            return $main->refresh();
        });
    }
}
