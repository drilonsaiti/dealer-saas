<?php

namespace App\Filament\App\Resources\Financings;

use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\Financing;
use App\Filament\App\Resources\Financings\Pages\ListFinancings;
use App\Filament\App\Resources\Financings\Pages\ViewFinancing;
use App\Filament\App\Resources\Financings\RelationManagers\PartnerChecklistRelationManager;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Support\Money;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Finance → Leasing: every financing with its status, the documents the bank needs and the
 * payout still to come (with its age).
 *
 * @extends resource<Financing>
 */
class FinancingResource extends Resource
{
    protected static ?string $model = Financing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'leasing';

    public static function getNavigationGroup(): string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Leasing / credit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Leasing / credit');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Financing::query()->awaitingPayout()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Payout outstanding');
    }

    public static function payoutAge(Financing $financing): ?int
    {
        $since = $financing->documents_sent_on ?? $financing->contract_received_on;

        return $since === null || $financing->status === FinancingStatus::PaidOut ? null : (int) $since->diffInDays(Carbon::today());
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (?int $v): string => $v === null ? '–' : Money::format($v);

        return $schema->components([
            Section::make(fn (Financing $record): string => $record->kind->getLabel().' '.$record->partner->displayName().($record->contract_number ? ' · '.$record->contract_number : ''))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('sale.stockCycle')->label(__('Vehicle file'))
                            ->state(fn (Financing $record): string => $record->sale->stockCycle->title())
                            ->url(fn (Financing $record): string => StockCycleResource::getUrl('view', ['record' => $record->sale->stockCycle])),
                        TextEntry::make('sale.buyer')->label(__('Customer (lessee)'))->state(fn (Financing $record): string => $record->sale->buyer->displayName()),
                        TextEntry::make('applied_on')->label(__('Applied on'))->date(),
                        TextEntry::make('cash_price_rp')->label(__('Cash price'))->formatStateUsing(fn (int $state): string => $money($state)),
                        TextEntry::make('collection_rp')->label(__('First instalment collected'))->formatStateUsing(fn (int $state): string => $money($state)),
                        TextEntry::make('payout_expected_rp')->label(__('Expected payout'))->formatStateUsing(fn (int $state): string => $money($state))->weight('bold'),
                        TextEntry::make('payout_received_on')->label(__('Paid out on'))->date()->placeholder(fn (Financing $record): string => ($age = self::payoutAge($record)) === null ? '–' : __('open for :days days', ['days' => $age])),
                        TextEntry::make('term_months')->label(__('Term'))->suffix(' '.__('months'))->placeholder('–'),
                        TextEntry::make('residual_rp')->label(__('Residual value'))->formatStateUsing(fn (?int $state): string => $money($state))->placeholder('–'),
                        TextEntry::make('monthly_rate_rp')->label(__('Monthly rate'))->formatStateUsing(fn (?int $state): string => $money($state))->placeholder('–'),
                        TextEntry::make('km_per_year')->label(__('km per year'))->numeric(thousandsSeparator: '’')->placeholder('–'),
                        TextEntry::make('contract_received_on')->label(__('Contract received'))->date()->placeholder('–'),
                        TextEntry::make('revocation_until')->label(__('Revocation possible until'))->date()->placeholder('–')
                            ->color(fn (Financing $record): ?string => $record->inRevocationPeriod() ? 'warning' : null),
                        TextEntry::make('documents_sent_on')->label(__('Documents sent'))->date()->placeholder('–'),
                        TextEntry::make('payout_due_on')->label(__('Payout due'))->date()->placeholder('–')
                            ->color(fn (Financing $record): ?string => $record->status !== FinancingStatus::PaidOut && $record->payout_due_on?->isPast() ? 'danger' : null),
                        TextEntry::make('buyback')->label(__('Buy-back obligation'))
                            ->state(fn (Financing $record): string => $record->buyback === null ? '–' : $money($record->buyback->amount_rp).' · '.$record->buyback->due_on->format('d.m.Y').' · '.$record->buyback->status->getLabel()),
                        TextEntry::make('vehicle_code178')->label(__('Code 178'))->badge()
                            ->state(fn (Financing $record) => $record->sale->stockCycle->vehicle->code178_status),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['partner', 'sale.buyer', 'sale.stockCycle.vehicle']))
            ->columns([
                TextColumn::make('sale.stockCycle')->label(__('Vehicle'))->state(fn (Financing $record): string => $record->sale->stockCycle->title())
                    ->description(fn (Financing $record): string => $record->sale->buyer->displayName()),
                TextColumn::make('partner.company_name')->label(__('Bank'))->state(fn (Financing $record): string => $record->partner->displayName())
                    ->description(fn (Financing $record): ?string => $record->contract_number),
                TextColumn::make('payout_expected_rp')->label(__('Expected payout'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state))->sortable(),
                TextColumn::make('payout_due_on')->label(__('Payout due'))->date()->placeholder('–')->sortable(),
                TextColumn::make('age')->label(__('Open for'))->alignEnd()
                    ->state(fn (Financing $record): ?string => ($age = self::payoutAge($record)) === null ? null : __(':days days', ['days' => $age]))->placeholder('–'),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('applied_on', 'desc')
            ->recordUrl(fn (Financing $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [PartnerChecklistRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancings::route('/'),
            'view' => ViewFinancing::route('/{record}'),
        ];
    }
}
