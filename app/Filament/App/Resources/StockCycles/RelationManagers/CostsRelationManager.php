<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Purchasing\Actions\ConfirmCost;
use App\Domain\Purchasing\Actions\RecordCost;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Costs of this vehicle file: draft until the real invoice is confirmed.
 */
class CostsRelationManager extends RelationManager
{
    protected static string $relationship = 'costs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Costs');
    }

    public function isReadOnly(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof StockCycle && $owner->isLocked();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(self::fields($this->cycle()));
    }

    /**
     * @return list<mixed>
     */
    public static function fields(?StockCycle $cycle): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('category_id')->label(__('Category'))->options(fn (): array => CostCategory::options())->required(),
                DatePicker::make('incurred_on')->label(__('Date'))->default(now())->required(),
                TextInput::make('description')->label(__('Description'))->maxLength(255)->columnSpan(2),
                PartySelect::make('supplier_party_id', [PartyRole::Workshop, PartyRole::Supplier, PartyRole::Transporter])->label(__('Supplier')),
                MoneyInput::make('gross_rp')->label(__('Amount incl. VAT'))->required(),
                MoneyInput::make('vat_rp')->label(__('of which VAT')),
                Select::make('commitment_id')
                    ->label(__('Fulfils promise'))
                    ->helperText(__('Replaces the estimate of this promise in the margin.'))
                    ->options(fn (): array => $cycle === null ? [] : $cycle->commitments()
                        ->whereNull('cost_id')
                        ->pluck('description', 'id')
                        ->all())
                    ->visible(fn (): bool => $cycle !== null && $cycle->commitments()->whereNull('cost_id')->exists()),
                Toggle::make('is_estimate')->label(__('Estimate (invoice not received yet)')),
            ]),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'supplier']))
            ->columns([
                TextColumn::make('incurred_on')->label(__('Date'))->date()->sortable(),
                TextColumn::make('category.name')->label(__('Category')),
                TextColumn::make('description')->label(__('Description'))->placeholder('–')->wrap()
                    ->description(fn (Cost $record): ?string => $record->supplier?->displayName()),
                TextColumn::make('gross_rp')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd()
                    ->summarize(Sum::make()->label(__('Total'))->formatStateUsing(fn (?int $state): string => Money::format($state ?? 0))),
                IconColumn::make('is_estimate')->label(__('Estimate'))->boolean()->trueIcon(Heroicon::OutlinedClock)->falseIcon(null),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('incurred_on', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add cost'))
                    ->using(fn (array $data): Cost => $this->save([...$data, 'stock_cycle_id' => $this->cycle()?->getKey()])),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label(__('Confirm'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Cost $record): bool => $record->status === CostStatus::Draft && (auth()->user()?->can('confirm', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(__('Confirm when the real invoice has been checked. Confirmed costs cannot be changed.'))
                    ->action(fn (Cost $record) => app(ConfirmCost::class)($record)),
                ActionGroup::make([
                    EditAction::make()->using(fn (Cost $record, array $data): Cost => $this->save($data, $record)),
                    DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(array $data, ?Cost $cost = null): Cost
    {
        try {
            return app(RecordCost::class)($data, $cost);
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    private function cycle(): ?StockCycle
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof StockCycle ? $owner : null;
    }
}
