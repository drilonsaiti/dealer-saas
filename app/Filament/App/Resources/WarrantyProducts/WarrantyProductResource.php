<?php

namespace App\Filament\App\Resources\WarrantyProducts;

use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Filament\App\Resources\WarrantyProducts\Pages\ManageWarrantyProducts;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Settings → Warranty products: own warranty and providers' products (SuisseFox FoxG3, NSA,
 * MultiPart …) with duration, km, cover, premium and price. Deactivate instead of delete.
 *
 * @extends resource<WarrantyProduct>
 */
class WarrantyProductResource extends Resource
{
    protected static ?string $model = WarrantyProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Warranty product');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Warranty products');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name.de')->label(__('Name (German)'))->required()->maxLength(120),
                TextInput::make('name.fr')->label(__('Name (French)'))->maxLength(120),
                TextInput::make('name.it')->label(__('Name (Italian)'))->maxLength(120),
                TextInput::make('name.en')->label(__('Name (English)'))->maxLength(120),
                PartySelect::make('provider_party_id', [PartyRole::WarrantyProvider])->label(__('Provider'))
                    ->helperText(__('Empty = your own warranty.')),
                TextInput::make('duration_months')->label(__('Duration'))->integer()->minValue(1)->suffix(__('months'))->required(),
                TextInput::make('km_limit')->label(__('km limit'))->integer()->minValue(0)->suffix('km')->helperText(__('From the mileage at handover. Empty = no limit.')),
                MoneyInput::make('coverage_limit_rp')->label(__('Coverage limit'))->nullable(),
                MoneyInput::make('deductible_rp')->label(__('Deductible'))->default(0)->required(),
                MoneyInput::make('cost_rp')->label(__('Premium (your cost)'))->default(0)->required(),
                MoneyInput::make('price_rp')->label(__('Price to the customer'))->default(0)->required(),
                MoneyInput::make('commission_rp')->label(__('Commission from the provider'))->default(0),
            ]),
            Grid::make(2)->schema([
                Select::make('submission')->label(__('Registrations and claims'))->live()->required()->default('manual')
                    ->options(['manual' => __('Own warranty / by hand'), 'email' => __('Send to the provider by e-mail')]),
                TextInput::make('provider_email')->label(__('E-mail of the provider'))->email()->maxLength(200)
                    ->visible(fn (Get $get): bool => $get('submission') === 'email')
                    ->helperText(__('Empty: the e-mail of the provider contact.')),
            ]),
            Textarea::make('coverage.de')->label(__('What is covered'))->rows(3),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('provider'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->description(fn (WarrantyProduct $record): string => $record->provider?->displayName() ?? __('own warranty')),
                TextColumn::make('duration_months')->label(__('Duration'))->suffix(' '.__('months')),
                TextColumn::make('km_limit')->label(__('km limit'))->numeric(thousandsSeparator: '’')->placeholder('–'),
                TextColumn::make('cost_rp')->label(__('Premium'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('price_rp')->label(__('Price'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (WarrantyProduct $record): array => [
                        ...$record->attributesToArray(),
                        'name' => $record->getTranslations('name'),
                        'coverage' => $record->getTranslations('coverage'),
                    ])
                    ->mutateDataUsing(fn (array $data): array => self::fillLanguages($data)),
            ])
            ->paginated(false);
    }

    /**
     * Missing languages take the German text, so every language shows a name.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillLanguages(array $data): array
    {
        foreach (['name', 'coverage'] as $field) {
            $values = array_filter((array) ($data[$field] ?? []), fn ($v): bool => filled($v));
            $first = reset($values);
            $data[$field] = $first === false ? null : array_merge(array_fill_keys((array) config('dealer.locales'), $first), $values);
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWarrantyProducts::route('/'),
        ];
    }
}
