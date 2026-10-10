<?php

namespace App\Filament\App\Resources\IntegrationAccounts;

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\Providers;
use App\Filament\App\Resources\IntegrationAccounts\Pages\ManageIntegrationAccounts;
use App\Filament\App\Resources\IntegrationAccounts\Pages\ViewIntegrationAccount;
use App\Filament\App\Resources\IntegrationAccounts\RelationManagers\LogsRelationManager;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Settings → Integrations: the dealer's own portal accounts (AutoScout24) with credentials,
 * on/off, connection test, "send all", first import and the sync log.
 *
 * @extends resource<IntegrationAccount>
 */
class IntegrationAccountResource extends Resource
{
    protected static ?string $model = IntegrationAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 62;

    protected static ?string $slug = 'integrations';

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Integration');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Integrations');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'ok' => __('Connected'),
            'error' => __('Error'),
            default => __('Not tested'),
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'ok' => 'success',
            'error' => 'danger',
            default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('provider')->label(__('Service'))->formatStateUsing(fn (string $state): string => Providers::label($state)),
                TextEntry::make('is_active')->label(__('Active'))->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Off'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextEntry::make('status')->label(__('Connection'))->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('seller')->label(__('Customer number'))
                    ->state(fn (IntegrationAccount $record): string => $record->credential('seller_id') ?? $record->credential('customer_number') ?? '–'),
                TextEntry::make('last_checked_at')->label(__('Last tested'))->since()->placeholder('–'),
                TextEntry::make('last_synced_at')->label(__('Last sync'))->since()->placeholder('–'),
                TextEntry::make('webhook_url')->label(__('Webhook address (callback URL)'))->copyable()->fontFamily('mono')->columnSpan(2)
                    ->visible(fn (IntegrationAccount $record): bool => $record->provider === IntegrationAccount::WHATSAPP)
                    ->state(fn (IntegrationAccount $record): string => route('webhooks.whatsapp.receive', ['account' => $record->getKey()]))
                    ->helperText(__('Enter it with the verify token in the Meta app (WhatsApp → Configuration) and subscribe to "messages".')),
                TextEntry::make('verify_token')->label(__('Verify token'))->copyable()->fontFamily('mono')
                    ->visible(fn (IntegrationAccount $record): bool => $record->provider === IntegrationAccount::WHATSAPP)
                    ->state(fn (IntegrationAccount $record): string => (string) $record->setting('verify_token')),
                TextEntry::make('last_error')->label(__('Last error'))->color('danger')->placeholder('–')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label(__('Service'))->formatStateUsing(fn (string $state): string => Providers::label($state)),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('status')->label(__('Connection'))->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('last_synced_at')->label(__('Last sync'))->since()->placeholder('–'),
                TextColumn::make('last_error')->label(__('Last error'))->limit(60)->color('danger')->placeholder('–'),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated(false);
    }

    public static function getRelations(): array
    {
        return [LogsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIntegrationAccounts::route('/'),
            'view' => ViewIntegrationAccount::route('/{record}'),
        ];
    }
}
