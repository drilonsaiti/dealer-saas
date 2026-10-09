<?php

namespace App\Filament\App\Resources\VatProfiles;

use App\Domain\Vat\Actions\SaveVatProfile;
use App\Domain\Vat\Enums\VatBasis;
use App\Domain\Vat\Enums\VatMethod;
use App\Domain\Vat\Enums\VatPeriodLength;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\VatNetTaxRate;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Models\VatProfile;
use App\Filament\App\Resources\VatProfiles\Pages\ManageVatProfiles;
use App\Support\BusinessRuleException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

/**
 * Settings → VAT: the dealer's VAT situation from a date on (liable, method, basis, period,
 * approved net tax rates). A change is new settings with a later start date.
 *
 * @extends resource<VatProfile>
 */
class VatProfileResource extends Resource
{
    protected static ?string $model = VatProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'vat-settings';

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('VAT');
    }

    public static function getModelLabel(): string
    {
        return __('VAT settings');
    }

    public static function getPluralModelLabel(): string
    {
        return __('VAT settings');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                DatePicker::make('valid_from')->label(__('Valid from'))->required()->native(false)->displayFormat('d.m.Y'),
                Toggle::make('liable')->label(__('VAT-registered'))->default(true)->live()->inline(false),
                TextInput::make('vat_number')->label(__('VAT number'))->placeholder('CHE-123.456.789 MWST')->maxLength(30)
                    ->default(fn (): ?string => Filament::getTenant()?->getAttribute('vat_number')),
                Select::make('method')->label(__('Method'))->options(VatMethod::class)->default(VatMethod::NetTaxRate->value)->required()->live()
                    ->helperText(__('The effective method is prepared but not released yet.')),
                Select::make('basis')->label(__('Basis'))->options(VatBasis::class)->default(VatBasis::Agreed->value)->required()
                    ->helperText(__('Received consideration only with approval of the ESTV.')),
                Select::make('period')->label(__('Return period'))->options(VatPeriodLength::class)->default(VatPeriodLength::HalfYear->value)->required(),
                DatePicker::make('approved_on')->label(__('Approved by the ESTV on'))->native(false)->displayFormat('d.m.Y'),
                TextInput::make('notes')->label(__('Note'))->maxLength(255),
            ]),
            Repeater::make('rates')
                ->label(__('Approved net tax rates'))
                ->visible(fn (Get $get): bool => (bool) $get('liable') && $get('method') !== VatMethod::Effective->value && $get('method') !== VatMethod::Effective)
                ->schema([
                    Hidden::make('id'),
                    Grid::make(3)->schema([
                        TextInput::make('activity')->label(__('Activity'))->placeholder(__('e.g. car trade'))->required()->maxLength(120),
                        TextInput::make('activity_code')->label(__('ESTV activity code'))->placeholder('12345')->maxLength(5)
                            ->helperText(__('Five digits, from the ESTV approval; needed for the XML export.')),
                        TextInput::make('rate')->label(__('Rate in %'))->numeric()->required()->minValue(0.01)->maxValue(99.99)->step(0.01)->placeholder('0.6'),
                    ]),
                ])
                ->defaultItems(1)
                ->maxItems(2)
                ->addActionLabel(__('Add second rate')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('netTaxRates'))
            ->columns([
                TextColumn::make('valid_from')->label(__('Valid from'))->date()->sortable(),
                TextColumn::make('valid_to')->label(__('Valid until'))->date()->placeholder(__('open')),
                IconColumn::make('liable')->label(__('VAT-registered'))->boolean(),
                TextColumn::make('method')->label(__('Method')),
                TextColumn::make('basis')->label(__('Basis'))->toggleable(),
                TextColumn::make('period')->label(__('Return period')),
                TextColumn::make('rates')->label(__('Net tax rates'))
                    ->state(fn (VatProfile $record): string => $record->netTaxRates
                        ->map(fn (VatNetTaxRate $rate): string => rtrim(rtrim((string) $rate->rate, '0'), '.').' % '.$rate->activity.($rate->activity_code ? ' ('.$rate->activity_code.')' : ''))
                        ->implode(', ') ?: '–'),
            ])
            ->defaultSort('valid_from', 'desc')
            ->recordActions([
                self::edit(),
            ])
            ->paginated(false);
    }

    public static function create(): CreateAction
    {
        return CreateAction::make()
            ->label(__('New VAT settings'))
            ->using(fn (array $data, Action $action): VatProfile => self::save(null, $data, $action));
    }

    public static function edit(): EditAction
    {
        return EditAction::make()
            ->visible(fn (VatProfile $record): bool => (auth()->user()?->can('update', $record) ?? false)
                && VatPeriod::query()->where('vat_profile_id', $record->getKey())->where('status', '!=', VatPeriodStatus::Open->value)->doesntExist())
            ->mutateRecordDataUsing(fn (array $data, VatProfile $record): array => [
                ...$data,
                'rates' => $record->netTaxRates->map(fn (VatNetTaxRate $rate): array => [
                    'id' => $rate->getKey(),
                    'activity' => $rate->activity,
                    'activity_code' => $rate->activity_code,
                    'rate' => rtrim(rtrim((string) $rate->rate, '0'), '.'),
                ])->all(),
            ])
            ->using(fn (VatProfile $record, array $data, Action $action): VatProfile => self::save($record, $data, $action));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function save(?VatProfile $profile, array $data, Action $action): VatProfile
    {
        $liable = (bool) ($data['liable'] ?? true);
        $rates = $liable && ($data['method'] ?? null) !== VatMethod::Effective->value ? array_values($data['rates'] ?? []) : [];

        try {
            return app(SaveVatProfile::class)($profile, Arr::except($data, ['rates']), $rates);
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();

            return $profile ?? new VatProfile;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVatProfiles::route('/'),
        ];
    }
}
