<?php

namespace App\Domain\Listings\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Listings\Models\Listing;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Creates or updates the advert of a vehicle file: texts per language (missing languages
 * take the first one given), public price, highlights and the chosen photos in order.
 */
class SaveListing
{
    /**
     * @param  array<string, mixed>  $data  title (array per locale or string), description, highlights, price_rp, show_price, photo_document_ids
     */
    public function __invoke(StockCycle $cycle, array $data = []): Listing
    {
        if ($cycle->isLocked()) {
            throw new BusinessRuleException(__('This vehicle file is closed and cannot be changed.'));
        }

        $listing = Listing::query()->firstOrNew(['stock_cycle_id' => $cycle->getKey()]);
        $defaults = $listing->exists ? [] : self::defaults($cycle);
        $data = [...$defaults, ...array_filter($data, fn ($v): bool => $v !== null)];

        foreach (['title', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = self::languages($data[$field]);
            }
        }

        if (is_array($data['description'] ?? null)) {
            $data['description'] = array_map([self::class, 'cleanHtml'], $data['description']);
        }

        if (is_array($data['title'] ?? null)) {
            $data['title'] = array_map(fn (string $t): string => trim(strip_tags($t)), $data['title']);
        }

        if (isset($data['photo_document_ids'])) {
            $data['photo_document_ids'] = self::validPhotos($cycle, (array) $data['photo_document_ids']);
        }

        if (! $listing->exists && ! isset($data['price_rp'])) {
            throw new BusinessRuleException(__('Enter the price.'));
        }

        if (isset($data['price_rp']) && (int) $data['price_rp'] <= 0) {
            throw new BusinessRuleException(__('Enter the price.'));
        }

        $listing->fill($data)->save();

        return $listing->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(StockCycle $cycle): array
    {
        return [
            'title' => $cycle->vehicle->displayName(),
            'description' => array_filter($cycle->vehicle->getTranslations('description'), fn ($v): bool => filled($v)) ?: null,
            'price_rp' => $cycle->list_price_rp ?? $cycle->planned_price_rp,
            'photo_document_ids' => self::photoQuery($cycle)->pluck('documents.id')->all(),
        ];
    }

    /**
     * Photos of the file, without damage photos (those are for the preparation, not the advert).
     *
     * @return Builder<Document>
     */
    public static function photoQuery(StockCycle $cycle): Builder
    {
        return Document::query()->linkedTo($cycle)
            ->whereHas('category', fn (Builder $q) => $q->where('key', 'photo'))
            ->whereDoesntHave('links', fn (Builder $q) => $q->where('linkable_type', 'damage'))
            ->orderBy('documents.created_at');
    }

    /**
     * The description goes to the dealer's website: only simple formatting, no attributes
     * (no links, scripts or styles).
     */
    public static function cleanHtml(string $html): string
    {
        $text = strip_tags($html, '<p><br><strong><em><b><i><ul><ol><li>');

        return trim((string) preg_replace('/<(\/?)(p|br|strong|em|b|i|ul|ol|li)\b[^>]*>/i', '<$1$2>', $text));
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<string>
     */
    private static function validPhotos(StockCycle $cycle, array $ids): array
    {
        $allowed = Document::query()->linkedTo($cycle)->whereHas('category', fn (Builder $q) => $q->where('key', 'photo'))->pluck('id')->all();

        return array_values(array_unique(array_filter(array_map('strval', $ids), fn (string $id): bool => in_array($id, $allowed, true))));
    }

    /**
     * @return array<string, string>|null
     */
    private static function languages(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $values = array_filter(is_array($value) ? $value : ['de' => (string) $value], fn ($v): bool => filled($v));

        if ($values === []) {
            return null;
        }

        $first = (string) reset($values);

        return array_merge(array_fill_keys((array) config('dealer.locales'), $first), array_map('strval', $values));
    }
}
