<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Vehicles\Enums\StockCycleStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who changed the status of this file, when and why. Read-only (append-only table).
 */
class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistory';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Status history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime(),
                TextColumn::make('from_status')
                    ->label(__('From'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '–' : (StockCycleStatus::tryFrom($state)?->getLabel() ?? $state))
                    ->placeholder('–'),
                TextColumn::make('to_status')
                    ->label(__('To'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StockCycleStatus::tryFrom($state)?->getLabel() ?? $state)
                    ->color(fn (string $state): string => StockCycleStatus::tryFrom($state)?->getColor() ?? 'gray'),
                TextColumn::make('user.name')->label(__('User'))->placeholder(__('System')),
                TextColumn::make('reason')->label(__('Reason'))->placeholder('–')->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
