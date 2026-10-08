<?php

namespace App\Filament\Platform\Resources\RestoreDrills;

use App\Domain\Operations\Models\RestoreDrill;
use App\Filament\Platform\Resources\RestoreDrills\Pages\ListRestoreDrills;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Platform → Restore drills: the log written by deploy/restore-test.sh. Read only.
 */
class RestoreDrillResource extends Resource
{
    protected static ?string $model = RestoreDrill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return __('Restore drill');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Restore drills');
    }

    public static function getNavigationBadge(): ?string
    {
        return RestoreDrill::isOverdue() ? __('Overdue') : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ran_at')->label(__('Date'))->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === RestoreDrill::STATUS_OK ? __('Successful') : __('Failed'))
                    ->color(fn (string $state): string => $state === RestoreDrill::STATUS_OK ? 'success' : 'danger'),
                TextColumn::make('backup_file')->label(__('Backup file'))->placeholder('–'),
                TextColumn::make('tenants')->label(__('Dealers'))->numeric()->placeholder('–'),
                TextColumn::make('rls_tables')->label(__('Protected tables'))->numeric()->placeholder('–'),
                TextColumn::make('audit_rows')->label(__('Audit log entries'))->numeric(thousandsSeparator: '’')->placeholder('–'),
                TextColumn::make('duration_seconds')->label(__('Duration'))->suffix(' s')->placeholder('–'),
                TextColumn::make('message')->label(__('Message'))->wrap()->placeholder('–'),
            ])
            ->defaultSort('ran_at', 'desc')
            ->emptyStateHeading(__('No restore drill recorded yet'))
            ->emptyStateDescription(__('deploy/restore-test.sh records every monthly drill here.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestoreDrills::route('/'),
        ];
    }
}
