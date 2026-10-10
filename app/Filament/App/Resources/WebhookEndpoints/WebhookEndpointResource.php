<?php

namespace App\Filament\App\Resources\WebhookEndpoints;

use App\Domain\Api\Models\WebhookEndpoint;
use App\Domain\Api\Support\Webhooks;
use App\Filament\App\Resources\WebhookEndpoints\Pages\ManageWebhookEndpoints;
use App\Filament\App\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Filament\App\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Settings → Webhooks: addresses that are told about events (listed, reserved, sold,
 * enquiry, invoice paid), signed with HMAC-SHA256; with the delivery log.
 *
 * @extends resource<WebhookEndpoint>
 */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?int $navigationSort = 61;

    protected static ?string $slug = 'webhooks';

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Webhook');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Webhooks');
    }

    /**
     * @return array<string, string>
     */
    public static function eventOptions(): array
    {
        return [
            'vehicle.listed' => __('Vehicle published'),
            'vehicle.reserved' => __('Vehicle reserved'),
            'vehicle.sold' => __('Vehicle sold'),
            'vehicle.unlisted' => __('Vehicle withdrawn'),
            'enquiry.received' => __('Enquiry received'),
            'invoice.paid' => __('Invoice paid'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('url')->label(__('Address (https)'))->url()->required()->maxLength(500)
                ->rule('starts_with:https://'),
            CheckboxList::make('events')->label(__('Events'))->options(self::eventOptions())->required()->columns(2),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('url')->label(__('Address (https)'))->columnSpan(2),
                TextEntry::make('is_active')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Off'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                TextEntry::make('secret')->label(__('Signing secret'))->copyable()->fontFamily('mono')
                    ->helperText(__('Header X-Dealer-Signature: t=<time>,v1=HMAC-SHA256(secret, "<time>.<body>")')),
                TextEntry::make('events')->label(__('Events'))->badge()->formatStateUsing(fn (string $state): string => self::eventOptions()[$state] ?? $state),
                TextEntry::make('last_success_at')->label(__('Last delivered'))->since()->placeholder('–'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('url')->label(__('Address (https)'))->limit(60),
                TextColumn::make('events')->label(__('Events'))->badge()->formatStateUsing(fn (string $state): string => self::eventOptions()[$state] ?? $state),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('consecutive_failures')->label(__('Failures in a row'))->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('last_success_at')->label(__('Last delivered'))->since()->placeholder('–'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'consecutive_failures' => ($data['is_active'] ?? false) ? 0 : null])])
            ->paginated(false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withSecret(array $data): array
    {
        return [...$data, 'secret' => Webhooks::newSecret()];
    }

    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWebhookEndpoints::route('/'),
            'view' => ViewWebhookEndpoint::route('/{record}'),
        ];
    }
}
