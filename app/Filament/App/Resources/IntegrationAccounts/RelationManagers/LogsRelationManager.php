<?php

namespace App\Filament\App\Resources\IntegrationAccounts\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every call to the portal: what, for which car, result, answer, duration.
 */
class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Sync log');
    }

    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'publish' => __('Publish'),
            'update' => __('Update'),
            'remove' => __('Remove'),
            'test' => __('Test connection'),
            'import' => __('Import'),
            'lookup' => __('Vehicle data'),
            'valuation' => __('Valuation'),
            default => $action,
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('listing'))
            ->columns([
                TextColumn::make('created_at')->label(__('Time'))->dateTime()->sortable(),
                TextColumn::make('action')->label(__('Action'))->formatStateUsing(fn (string $state): string => self::actionLabel($state)),
                TextColumn::make('listing.title')->label(__('Vehicle'))->placeholder('–')->limit(40),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'ok' ? __('OK') : __('Error'))
                    ->color(fn (string $state): string => $state === 'ok' ? 'success' : 'danger'),
                TextColumn::make('response_code')->label(__('Answer'))->placeholder('–'),
                TextColumn::make('message')->label(__('Message'))->wrap()->limit(200)->placeholder('–'),
                TextColumn::make('duration_ms')->label(__('Duration'))->formatStateUsing(fn (?int $state): string => $state === null ? '–' : $state.' ms'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(['ok' => __('OK'), 'error' => __('Error')]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(null)
            ->recordAction(null);
    }
}
