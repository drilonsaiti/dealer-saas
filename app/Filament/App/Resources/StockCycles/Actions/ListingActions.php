<?php

namespace App\Filament\App\Resources\StockCycles\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Listings\Actions\PublishListing;
use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Listings\Enums\ListingStatus;
use App\Domain\Listings\Models\Listing;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\Support\MoneyInput;
use App\Support\BusinessRuleException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The advert of a vehicle file: texts per language, price, photos (with cover) → publish on
 * the dealer's website (public API) and, with the connector, on portals; withdraw.
 */
final class ListingActions
{
    public static function edit(): Action
    {
        return Action::make('listing')
            ->label(fn (StockCycle $record): string => self::listing($record)?->status === ListingStatus::Published ? __('Edit listing') : __('Publish listing'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->visible(fn (StockCycle $record): bool => in_array($record->status, [StockCycleStatus::ReadyForSale, StockCycleStatus::Listed, StockCycleStatus::Reserved], true)
                && (auth()->user()?->can('update', $record) ?? false))
            ->modalWidth('4xl')
            ->fillForm(function (StockCycle $record): array {
                $listing = self::listing($record);

                if ($listing === null) {
                    $defaults = SaveListing::defaults($record);

                    return [
                        'title' => ['de' => $defaults['title']],
                        'description' => $defaults['description'] ?? [],
                        'highlights' => [],
                        'price_rp' => $defaults['price_rp'],
                        'show_price' => true,
                        'photos' => $defaults['photo_document_ids'],
                        'cover' => $defaults['photo_document_ids'][0] ?? null,
                    ];
                }

                return [
                    'title' => $listing->getTranslations('title'),
                    'description' => $listing->getTranslations('description'),
                    'highlights' => $listing->highlights ?? [],
                    'price_rp' => $listing->price_rp,
                    'show_price' => $listing->show_price,
                    'photos' => $listing->photo_document_ids ?? [],
                    'cover' => ($listing->photo_document_ids ?? [])[0] ?? null,
                ];
            })
            ->schema(fn (StockCycle $record): array => [
                Tabs::make()->tabs(array_map(fn (string $locale): Tab => Tab::make(strtoupper($locale))->schema([
                    TextInput::make("title.{$locale}")->label(__('Title'))->required($locale === 'de')->maxLength(120),
                    RichEditor::make("description.{$locale}")->label(__('Description'))->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList'])
                        ->helperText($locale === 'de' ? __('Languages left empty use the German text.') : null),
                ]), array_values((array) config('dealer.locales')))),
                TagsInput::make('highlights')->label(__('Highlights'))->placeholder(__('e.g. 1st owner, service book, winter tyres'))->splitKeys(['Enter', ',']),
                Grid::make(2)->schema([
                    MoneyInput::make('price_rp')->label(__('Price'))->required(),
                    Toggle::make('show_price')->label(__('Show the price'))->default(true)->inline(false),
                ]),
                CheckboxList::make('photos')->label(__('Photos'))->live()
                    ->options(SaveListing::photoQuery($record)->get()->mapWithKeys(fn (Document $d): array => [$d->getKey() => $d->title])->all())
                    ->helperText(__('Photos are uploaded in the Documents tab (category "Photo").'))
                    ->columns(2),
                Select::make('cover')->label(__('Cover photo'))
                    ->options(fn (Get $get): array => SaveListing::photoQuery($record)->whereIn('documents.id', (array) $get('photos'))->get()->mapWithKeys(fn (Document $d): array => [$d->getKey() => $d->title])->all()),
            ])
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveDraft', ['publish' => false])->label(__('Save without publishing'))->color('gray'),
            ])
            ->modalSubmitActionLabel(__('Publish'))
            ->action(function (StockCycle $record, array $data, array $arguments, Action $action): void {
                $photos = array_values((array) ($data['photos'] ?? []));

                if (filled($data['cover'] ?? null) && in_array($data['cover'], $photos, true)) {
                    $photos = [$data['cover'], ...array_values(array_diff($photos, [$data['cover']]))];
                }

                self::run($action, function () use ($record, $data, $photos, $arguments): void {
                    $listing = app(SaveListing::class)($record, [
                        'title' => $data['title'],
                        'description' => array_filter((array) ($data['description'] ?? []), fn ($v): bool => filled(strip_tags((string) $v))),
                        'highlights' => array_values((array) ($data['highlights'] ?? [])),
                        'price_rp' => $data['price_rp'],
                        'show_price' => (bool) ($data['show_price'] ?? true),
                        'photo_document_ids' => $photos,
                    ]);

                    if (($arguments['publish'] ?? true) === false) {
                        Notification::make()->title(__('Listing saved.'))->success()->send();

                        return;
                    }

                    app(PublishListing::class)($listing);
                    Notification::make()->title(__('Listing is online.'))->success()->send();
                });
            });
    }

    public static function withdraw(): Action
    {
        return Action::make('withdrawListing')
            ->label(__('Withdraw listing'))
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('gray')
            ->visible(fn (StockCycle $record): bool => self::listing($record)?->status === ListingStatus::Published && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('The car disappears from the website and the portals; the file goes back to "ready for sale".'))
            ->action(fn (StockCycle $record, Action $action) => self::run($action, function () use ($record): void {
                app(PublishListing::class)->withdraw(self::listing($record));
                Notification::make()->title(__('Listing withdrawn.'))->success()->send();
            }));
    }

    public static function listing(StockCycle $cycle): ?Listing
    {
        return Listing::query()->where('stock_cycle_id', $cycle->getKey())->first();
    }

    /**
     * @param  Closure(): void  $callback
     */
    private static function run(Action $action, Closure $callback): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();
            $action->halt();
        }
    }
}
