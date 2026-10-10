<?php

namespace App\Domain\Integrations\Actions;

use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Models\IntegrationLog;
use App\Domain\Integrations\Support\Channels;
use App\Domain\Integrations\Support\IntegrationException;
use App\Domain\Integrations\Support\PortalVehicle;
use App\Domain\Listings\Enums\PublicationStatus;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingPublication;
use App\Domain\Vehicles\Actions\OpenStockCycle;
use App\Domain\Vehicles\Enums\FuelType;
use App\Domain\Vehicles\Enums\Transmission;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Support\Vin;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * First import from a portal: every vehicle the dealer already advertises there becomes a
 * vehicle file "in review" with its list price, an advert draft (texts, photos) and the link to
 * the portal entry. Running it again only adds what is new (the portal id is the key); a car
 * whose VIN is already known is linked instead of created twice.
 *
 * The advert stays a draft: once the file is complete and released, "Publish" updates the
 * existing portal entry instead of creating a second one.
 */
class ImportPortalStock
{
    public function __construct(
        private readonly OpenStockCycle $openStockCycle,
        private readonly StoreDocument $storeDocument,
    ) {}

    /**
     * @return array{created: int, linked: int, skipped: int, photos: int, errors: list<string>}
     */
    public function __invoke(IntegrationAccount $account, bool $withPhotos = true): array
    {
        $summary = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'photos' => 0, 'errors' => []];
        $started = hrtime(true);

        try {
            foreach (Channels::for($account->provider)->stock($account) as $vehicle) {
                try {
                    $this->import($account, $vehicle, $withPhotos, $summary);
                } catch (BusinessRuleException $e) {
                    $summary['skipped']++;
                    $summary['errors'][] = "{$vehicle->make} {$vehicle->model} ({$vehicle->externalId}): {$e->getMessage()}";
                }
            }
        } catch (IntegrationException $e) {
            $summary['errors'][] = $e->getMessage();
        }

        IntegrationLog::query()->create([
            'integration_account_id' => $account->getKey(),
            'action' => 'import',
            'status' => $summary['errors'] === [] ? 'ok' : 'error',
            'message' => trim(__(':created new, :linked linked, :skipped skipped, :photos photos', [
                'created' => $summary['created'], 'linked' => $summary['linked'], 'skipped' => $summary['skipped'], 'photos' => $summary['photos'],
            ]).($summary['errors'] !== [] ? "\n".implode("\n", array_slice($summary['errors'], 0, 20)) : '')),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        return $summary;
    }

    /**
     * @param  array{created: int, linked: int, skipped: int, photos: int, errors: list<string>}  $summary
     */
    private function import(IntegrationAccount $account, PortalVehicle $portal, bool $withPhotos, array &$summary): void
    {
        $known = ListingPublication::query()->where('channel', $account->provider)->where('external_id', $portal->externalId)->exists();

        if ($known) {
            $summary['skipped']++;

            return;
        }

        if ($portal->priceRp === null || $portal->priceRp <= 0) {
            throw new BusinessRuleException(__('The portal entry has no price.'));
        }

        [$cycle, $listing, $isNew] = DB::transaction(function () use ($account, $portal): array {
            $vin = Vin::normalize($portal->vin);
            $vehicle = $vin !== null ? Vehicle::query()->where('vin', $vin)->first() : null;
            $isNew = $vehicle === null;

            $vehicle ??= Vehicle::create(array_filter([
                'make' => $portal->make,
                'model' => $portal->model,
                'variant' => $portal->variant,
                'vin' => $vin,
                'first_registration_on' => $portal->firstRegistration,
                'fuel' => FuelType::tryFrom((string) $portal->fuel),
                'transmission' => Transmission::tryFrom((string) $portal->transmission),
                'power_kw' => $portal->powerKw,
                'color_exterior' => $portal->color,
            ], fn ($v): bool => $v !== null));

            $cycle = $vehicle->openStockCycle()->first()
                ?? ($this->openStockCycle)($vehicle, array_filter([
                    'list_price_rp' => $portal->priceRp,
                    'planned_price_rp' => $portal->priceRp,
                    'mileage_in' => $portal->mileage,
                    'notes' => __('Imported from :portal', ['portal' => Channels::label($account->provider)]),
                ], fn ($v): bool => $v !== null));

            /** @var StockCycle $cycle */
            $listing = Listing::query()->firstOrNew(['stock_cycle_id' => $cycle->getKey()]);

            if (! $listing->exists) {
                $title = trim("{$portal->make} {$portal->model} ".($portal->variant ?? ''));
                $locales = (array) config('dealer.locales');
                $description = $portal->description !== null ? strip_tags($portal->description, '<p><br><strong><em><b><i><ul><ol><li>') : null;

                $listing->fill([
                    'title' => array_fill_keys($locales, $title),
                    'description' => $description !== null ? array_fill_keys($locales, $description) : null,
                    'price_rp' => $portal->priceRp,
                    'show_price' => true,
                ])->save();
            }

            ListingPublication::query()->updateOrCreate(
                ['listing_id' => $listing->getKey(), 'channel' => $account->provider],
                [],
            )->forceFill([
                'status' => PublicationStatus::Published,
                'external_id' => $portal->externalId,
                'external_url' => $portal->url,
                'last_synced_at' => now(),
            ])->save();

            return [$cycle, $listing, $isNew];
        });

        $summary[$isNew ? 'created' : 'linked']++;

        if ($withPhotos && $portal->photoUrls !== [] && ($listing->photo_document_ids ?? []) === []) {
            $ids = $this->photos($cycle, $portal, $summary);

            if ($ids !== []) {
                $listing->forceFill(['photo_document_ids' => $ids])->save();
            }
        }
    }

    /**
     * Downloads the portal photos into the vehicle file (category "photo"). A photo that cannot
     * be fetched is skipped; the rest of the import goes on.
     *
     * @param  array{created: int, linked: int, skipped: int, photos: int, errors: list<string>}  $summary
     * @return list<string>
     */
    private function photos(StockCycle $cycle, PortalVehicle $portal, array &$summary): array
    {
        $category = DocumentCategory::query()->where('key', 'photo')->first();

        if ($category === null) {
            return [];
        }

        $ids = [];

        foreach (array_slice($portal->photoUrls, 0, (int) config('integrations.autoscout24.max_photos', 30)) as $i => $url) {
            if (! str_starts_with($url, 'https://')) {
                continue;
            }

            $path = (string) tempnam(sys_get_temp_dir(), 'portal');

            try {
                $response = Http::timeout(20)->sink($path)->get($url);
                $type = (string) $response->header('Content-Type');

                if (! $response->successful() || ! str_starts_with($type, 'image/') || filesize($path) > 15 * 1024 * 1024) {
                    continue;
                }

                $extension = match (true) {
                    str_contains($type, 'png') => 'png',
                    str_contains($type, 'webp') => 'webp',
                    default => 'jpg',
                };

                $document = ($this->storeDocument)($path, $category, [
                    'title' => __('Photo').' '.($i + 1),
                    'original_name' => 'portal-'.($i + 1).'.'.$extension,
                    'source' => DocumentSource::Import,
                ], [$cycle]);

                $ids[] = $document->getKey();
                $summary['photos']++;
            } catch (Throwable) {
                // duplicate or unreachable photo: not worth stopping the import
            } finally {
                @unlink($path);
            }
        }

        return $ids;
    }
}
