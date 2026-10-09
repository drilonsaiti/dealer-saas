<?php

namespace App\Filament\App\Resources\VatPeriods\RelationManagers;

use App\Domain\Vat\Actions\ConfirmTaxEvent;
use App\Domain\Vat\Enums\TaxEventState;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Models\VatNetTaxRate;
use App\Domain\Vat\Models\VatPeriod;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Filament\App\Resources\VatPeriods\VatPeriodResource;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every entry of the period with the rule that made it and why; "please confirm" entries are
 * confirmed here, blocked ones show what is missing.
 */
class TaxEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Entries');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice.stockCycle.vehicle', 'netTaxRate']))
            ->columns([
                TextColumn::make('event_on')->label(__('Date'))->date()->sortable()
                    ->description(fn (TaxEvent $record): ?string => $record->late ? __('late') : null),
                TextColumn::make('invoice.number')->label(__('Invoice'))->fontFamily('mono')->placeholder('–')
                    ->url(fn (TaxEvent $record): ?string => $record->invoice === null ? null : InvoiceResource::getUrl('view', ['record' => $record->invoice]))
                    ->description(fn (TaxEvent $record): ?string => $record->invoice?->stockCycle?->title()),
                TextColumn::make('field')->label(__('Field')),
                TextColumn::make('base_rp')->label(__('Amount'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('tax_rp')->label(__('Tax'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->description(fn (TaxEvent $record): ?string => $record->net_rate === null ? null : rtrim(rtrim((string) $record->net_rate, '0'), '.').' %'),
                TextColumn::make('state')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (TaxEvent $record): string => $record->counts() ? __('Counts') : $record->state->getLabel())
                    ->color(fn (TaxEvent $record): string => $record->counts() ? 'success' : $record->state->getColor()),
                TextColumn::make('explanation')->label(__('Why'))->wrap()
                    ->state(fn (TaxEvent $record): string => $record->explanationText())
                    ->description(fn (TaxEvent $record): string => $record->rule_key.' '.$record->rule_version),
            ])
            ->filters([
                SelectFilter::make('state')->label(__('Status'))->options(TaxEventState::class),
            ])
            ->defaultSort('event_on')
            ->recordActions([
                Action::make('confirm')
                    ->label(__('Confirm'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (TaxEvent $record): bool => $record->state === TaxEventState::Confirm && $record->confirmed_at === null
                        && $this->periodOpen() && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                    ->modalHeading(__('Confirm entry'))
                    ->modalDescription(fn (TaxEvent $record): string => $record->explanationText())
                    ->schema(fn (TaxEvent $record): array => $record->net_tax_rate_id === null ? [] : [
                        Select::make('net_tax_rate_id')->label(__('Net tax rate'))
                            ->options(fn (): array => VatNetTaxRate::query()->where('vat_profile_id', $record->netTaxRate?->vat_profile_id)->orderBy('sort')->get()
                                ->mapWithKeys(fn (VatNetTaxRate $rate): array => [$rate->getKey() => rtrim(rtrim((string) $rate->rate, '0'), '.').' % '.$rate->activity])->all())
                            ->default($record->net_tax_rate_id)->required(),
                    ])
                    ->action(function (TaxEvent $record, array $data, Action $action): void {
                        try {
                            app(ConfirmTaxEvent::class)($record, $data['net_tax_rate_id'] ?? null);
                        } catch (BusinessRuleException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        /** @var VatPeriod $period */
                        $period = $this->getOwnerRecord();
                        VatPeriodResource::forget($period);
                        Notification::make()->title(__('Confirmed.'))->success()->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    private function periodOpen(): bool
    {
        $period = $this->getOwnerRecord();

        return $period instanceof VatPeriod && $period->status === VatPeriodStatus::Open;
    }
}
