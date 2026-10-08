<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Purchasing\Actions\CompleteCommitment;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\Support\MoneyInput;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Zusagen: what was promised to the customer. Open promises block the handover.
 */
class CommitmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'commitments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Promises to customer');
    }

    public function isReadOnly(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof StockCycle && $owner->isLocked();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('description')
                    ->label(__('What was promised'))
                    ->placeholder(__('e.g. 4 new summer tyres'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(2),
                MoneyInput::make('estimated_cost_rp')->label(__('Estimated cost'))->required(),
                DatePicker::make('due_on')->label(__('Due by')),
                Toggle::make('blocks_handover')->label(__('Must be done before handover'))->default(true),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['cost', 'doneBy']))
            ->columns([
                IconColumn::make('done_at')
                    ->label(__('Done'))
                    ->state(fn (Commitment $record): bool => $record->isDone())
                    ->boolean(),
                TextColumn::make('description')->label(__('What was promised'))->wrap()
                    ->description(fn (Commitment $record): ?string => $record->isDone()
                        ? __('Done on :date by :user', ['date' => $record->done_at?->format('d.m.Y'), 'user' => $record->doneBy !== null ? $record->doneBy->name : '–'])
                        : null),
                TextColumn::make('estimated_cost_rp')
                    ->label(__('Estimated cost'))
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('cost.gross_rp')
                    ->label(__('Actual cost'))
                    ->formatStateUsing(fn (?int $state): string => Money::format($state))
                    ->placeholder('–')
                    ->alignEnd(),
                TextColumn::make('due_on')->label(__('Due by'))->date()->placeholder('–'),
                IconColumn::make('blocks_handover')->label(__('Blocks handover'))->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Add promise')),
            ])
            ->recordActions([
                Action::make('done')
                    ->label(__('Mark as done'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (Commitment $record): bool => ! $record->isDone() && (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (Commitment $record) => app(CompleteCommitment::class)($record)),
                Action::make('reopen')
                    ->label(__('Reopen'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (Commitment $record): bool => $record->isDone() && (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (Commitment $record) => app(CompleteCommitment::class)($record, done: false)),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
