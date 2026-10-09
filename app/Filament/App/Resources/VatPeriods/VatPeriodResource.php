<?php

namespace App\Filament\App\Resources\VatPeriods;

use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Support\PeriodCalculator;
use App\Filament\App\Resources\VatPeriods\Pages\ListVatPeriods;
use App\Filament\App\Resources\VatPeriods\Pages\ViewVatPeriod;
use App\Filament\App\Resources\VatPeriods\RelationManagers\TaxEventsRelationManager;
use App\Support\Money;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use WeakMap;

/**
 * Finance → VAT: one return per period. Open periods show a live preview from the tax
 * events; closed periods show their frozen figures.
 *
 * @extends resource<VatPeriod>
 */
class VatPeriodResource extends Resource
{
    protected static ?string $model = VatPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'vat';

    /** @var WeakMap<VatPeriod, array<string, mixed>>|null */
    private static ?WeakMap $figures = null;

    public static function getNavigationGroup(): string
    {
        return __('Finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('VAT');
    }

    public static function getModelLabel(): string
    {
        return __('VAT period');
    }

    public static function getPluralModelLabel(): string
    {
        return __('VAT periods');
    }

    /**
     * Frozen figures of a closed period, otherwise the live preview (computed once per request).
     *
     * @return array<string, mixed>
     */
    public static function figures(VatPeriod $period): array
    {
        if ($period->status !== VatPeriodStatus::Open && $period->figures !== null) {
            return $period->figures;
        }

        self::$figures ??= new WeakMap;

        return self::$figures[$period] ??= app(PeriodCalculator::class)($period);
    }

    public static function forget(VatPeriod $period): void
    {
        self::$figures?->offsetUnset($period);
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (VatPeriod $record, int $field): string => Money::format((int) (self::figures($record)['fields'][$field] ?? 0));

        return $schema->components([
            Section::make(fn (VatPeriod $record): string => $record->status === VatPeriodStatus::Open && ! self::figures($record)['complete']
                    ? __('Preview, not complete')
                    : ($record->status === VatPeriodStatus::Open ? __('Preview, complete: ready to close') : __('Closed figures')))
                ->icon(fn (VatPeriod $record): Heroicon => $record->status === VatPeriodStatus::Open && ! self::figures($record)['complete'] ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->iconColor(fn (VatPeriod $record): string => $record->status === VatPeriodStatus::Open && ! self::figures($record)['complete'] ? 'warning' : 'success')
                ->schema([
                    TextEntry::make('checks')->hiddenLabel()
                        ->visible(fn (VatPeriod $record): bool => $record->status === VatPeriodStatus::Open && self::figures($record)['checks'] !== [])
                        ->state(fn (VatPeriod $record): array => array_map(fn (array $c): string => (string) __($c['text'], $c['params']), self::figures($record)['checks']))
                        ->listWithLineBreaks()->bulleted()->color('warning'),
                    Grid::make(4)->schema([
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('profile.method')->label(__('Method')),
                        TextEntry::make('profile.basis')->label(__('Basis')),
                        TextEntry::make('payable')->label(__('Amount payable (500)'))
                            ->state(fn (VatPeriod $record): string => Money::format((int) self::figures($record)['payable_rp']))
                            ->weight('bold')->size('lg'),
                        TextEntry::make('closed_at')->label(__('Closed'))->dateTime()->placeholder('–'),
                        TextEntry::make('exported_at')->label(__('Exported'))->dateTime()->placeholder('–'),
                        TextEntry::make('submitted_on')->label(__('Submitted'))->date()->placeholder('–')
                            ->helperText(fn (VatPeriod $record): ?string => $record->submission_reference),
                        TextEntry::make('paid_on')->label(__('Paid'))->date()->placeholder('–'),
                    ]),
                ]),
            Section::make(__('Turnover'))
                ->columns(3)
                ->schema([
                    TextEntry::make('f200')->label('200 · '.__('Total consideration'))->state(fn (VatPeriod $r): string => $money($r, 200)),
                    TextEntry::make('f220')->label('220 · '.__('Exports'))->state(fn (VatPeriod $r): string => $money($r, 220)),
                    TextEntry::make('f230')->label('230 · '.__('Excluded from VAT'))->state(fn (VatPeriod $r): string => $money($r, 230)),
                    TextEntry::make('f235')->label('235 · '.__('Reductions (credit notes)'))->state(fn (VatPeriod $r): string => $money($r, 235)),
                    TextEntry::make('f289')->label('289 · '.__('Total deductions'))->state(fn (VatPeriod $r): string => $money($r, 289)),
                    TextEntry::make('f299')->label('299 · '.__('Taxable turnover'))->state(fn (VatPeriod $r): string => $money($r, 299))->weight('bold'),
                ]),
            Section::make(__('Tax calculation (net tax rates)'))
                ->schema([
                    RepeatableEntry::make('rates')->hiddenLabel()
                        ->state(fn (VatPeriod $record): array => array_map(fn (array $rate): array => [
                            ...$rate,
                            'activity' => is_array($rate['activity']) ? ($rate['activity'][app()->getLocale()] ?? (string) reset($rate['activity'])) : (string) $rate['activity'],
                        ], self::figures($record)['rates']))
                        ->placeholder(__('No taxable turnover.'))
                        ->table([
                            RepeatableEntry\TableColumn::make(__('Activity')),
                            RepeatableEntry\TableColumn::make(__('Rate'))->alignment('end'),
                            RepeatableEntry\TableColumn::make(__('Turnover'))->alignment('end'),
                            RepeatableEntry\TableColumn::make(__('Tax'))->alignment('end'),
                        ])
                        ->schema([
                            TextEntry::make('activity'),
                            TextEntry::make('rate')->formatStateUsing(fn ($state): string => rtrim(rtrim((string) $state, '0'), '.').' %'),
                            TextEntry::make('turnover_rp')->formatStateUsing(fn ($state): string => Money::format((int) $state)),
                            TextEntry::make('tax_rp')->formatStateUsing(fn ($state): string => Money::format((int) $state)),
                        ]),
                    TextEntry::make('legal_vat')->label(__('VAT shown on the invoices (information)'))
                        ->state(fn (VatPeriod $record): string => Money::format((int) self::figures($record)['legal_vat_rp']))
                        ->helperText(__('Under the net tax rate method the dealer owes the net tax, not the VAT shown.')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('profile'))
            ->columns([
                TextColumn::make('starts_on')->label(__('Period'))->state(fn (VatPeriod $record): string => $record->label())->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('turnover')->label(__('Taxable turnover'))->alignEnd()
                    ->state(fn (VatPeriod $record): string => Money::format((int) (self::figures($record)['fields'][299] ?? 0))),
                TextColumn::make('payable')->label(__('Payable'))->alignEnd()->weight('bold')
                    ->state(fn (VatPeriod $record): string => Money::format((int) self::figures($record)['payable_rp'])),
                TextColumn::make('checks')->label(__('Open checks'))->alignEnd()->badge()
                    ->state(fn (VatPeriod $record): int => $record->status === VatPeriodStatus::Open ? count(self::figures($record)['checks']) : 0)
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success'),
            ])
            ->defaultSort('starts_on', 'desc')
            ->recordUrl(fn (VatPeriod $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [TaxEventsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVatPeriods::route('/'),
            'view' => ViewVatPeriod::route('/{record}'),
        ];
    }
}
