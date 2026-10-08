<?php

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The name a document gets when it is downloaded or exported, so 100 contracts do not all
 * arrive as "Kaufvertrag.pdf":
 *
 *   {folder no}_{folder}_{car}_{type}_{date}_{running no}.{ext}
 *   01_Ankauf_BMW-X3_Kaufvertrag_2026-07-14_01.pdf
 *
 * The running number counts the documents of the same type in the same vehicle file in the
 * order they were added, so it never changes when later documents come in. Older versions of
 * a document get "_v1", "_v2" ... in front of the extension.
 */
class DocumentFileName
{
    private const MAX_LENGTH = 140;

    public static function make(Document $document, DocumentVersion $version, ?StockCycle $cycle, int $sequence, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $category = $document->category;

        $parts = [
            $category->folder_group->number(),
            self::slug($category->folder_group->labelIn($locale), $locale),
            $cycle === null ? null : self::slug(self::carName($cycle), $locale),
            self::slug((string) $category->getTranslation('name', $locale), $locale),
            $document->document_on?->format('Y-m-d') ?? 'UNDATIERT',
            sprintf('%02d', $sequence),
        ];

        $name = implode('_', array_filter($parts, fn (?string $part): bool => filled($part)));
        $name = Str::limit($name, self::MAX_LENGTH, '');

        if ($version->getKey() !== $document->current_version_id) {
            $name .= '_v'.$version->version_no;
        }

        return $name.'.'.$version->extension();
    }

    /**
     * The name for a single download: the vehicle file the document is linked to (if any).
     */
    public static function forDownload(Document $document, ?DocumentVersion $version = null): string
    {
        $version ??= $document->currentVersion;
        $document->loadMissing('category');

        $link = $document->links()->where('linkable_type', (new StockCycle)->getMorphClass())->first();
        $cycle = $link === null ? null : StockCycle::query()->with('vehicle')->find($link->linkable_id);

        $same = Document::query()
            ->where('category_id', $document->category_id)
            ->when(
                $cycle !== null,
                fn ($query) => $query->linkedTo($cycle),
                fn ($query) => $query->whereDoesntHave('links', fn ($links) => $links->where('linkable_type', (new StockCycle)->getMorphClass())),
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id');

        return self::make($document, $version, $cycle, ((int) $same->search($document->getKey())) + 1);
    }

    /**
     * Running number of a document among those of the same type in the given list (add order).
     *
     * @param  Collection<int, Document>  $documents  all documents of one vehicle file
     */
    public static function sequenceIn(Collection $documents, Document $document): int
    {
        $rank = $documents
            ->where('category_id', $document->category_id)
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values()
            ->search(fn (Document $other): bool => $other->is($document));

        return ((int) $rank) + 1;
    }

    private static function carName(StockCycle $cycle): string
    {
        $vehicle = $cycle->vehicle;
        $name = trim(($vehicle->make ?? '').' '.($vehicle->model ?? ''));

        return $name !== '' ? $name : $vehicle->displayName();
    }

    /**
     * ASCII only (ä → ae in German), words joined by "-", safe in every file system.
     */
    private static function slug(string $text, string $locale): string
    {
        $ascii = Str::ascii($text, $locale === 'de' ? 'de' : 'en');

        return trim((string) preg_replace('/[^A-Za-z0-9.]+/', '-', $ascii), '-.');
    }
}
