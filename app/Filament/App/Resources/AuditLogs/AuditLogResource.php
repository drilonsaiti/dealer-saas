<?php

namespace App\Filament\App\Resources\AuditLogs;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\MorphMap;
use App\Filament\App\Resources\AuditLogs\Pages\ListAuditLogs;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Settings → Change log: who changed what and when. Read-only.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'change-log';

    protected static ?string $tenantRelationshipName = 'allAuditLogs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Change');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Change log');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextEntry::make('created_at')->label(__('When'))->dateTime('d.m.Y H:i:s'),
                TextEntry::make('user.name')->label(__('Who'))->placeholder(__('System')),
                TextEntry::make('event')->label(__('Action'))->formatStateUsing(fn (string $state): string => self::eventLabel($state)),
                TextEntry::make('auditable_type')->label(__('Record'))->formatStateUsing(fn (string $state): string => MorphMap::label($state)),
                KeyValueEntry::make('old_values')->label(__('Before'))->columnSpanFull(),
                KeyValueEntry::make('new_values')->label(__('After'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('user.name')->label(__('Who'))->placeholder(__('System')),
                TextColumn::make('event')
                    ->label(__('Action'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => self::eventLabel($state)),
                TextColumn::make('auditable_type')
                    ->label(__('Record'))
                    ->formatStateUsing(fn (string $state): string => MorphMap::label($state)),
                TextColumn::make('auditable_id')->label(__('ID'))->limit(8)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('event')
                    ->label(__('Action'))
                    ->options([
                        'created' => self::eventLabel('created'),
                        'updated' => self::eventLabel('updated'),
                        'deleted' => self::eventLabel('deleted'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }

    private static function eventLabel(string $event): string
    {
        return match ($event) {
            'created' => __('Created'),
            'updated' => __('Changed'),
            'deleted' => __('Deleted'),
            default => $event,
        };
    }
}
