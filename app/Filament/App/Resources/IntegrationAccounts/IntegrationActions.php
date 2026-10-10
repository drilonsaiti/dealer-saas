<?php

namespace App\Filament\App\Resources\IntegrationAccounts;

use App\Domain\Integrations\Actions\SaveIntegrationAccount;
use App\Domain\Integrations\Actions\TestIntegration;
use App\Domain\Integrations\Jobs\ImportPortalStockJob;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\Channels;
use App\Domain\Integrations\Support\ListingSync;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

/**
 * The actions of Settings → Integrations (connect/edit, test, send all, import).
 */
final class IntegrationActions
{
    public static function connect(): Action
    {
        return Action::make('connect')
            ->label(__('Connect portal'))
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (): bool => (auth()->user()?->can('create', IntegrationAccount::class) ?? false)
                && self::freeProviders() !== [])
            ->schema(fn (): array => [
                Select::make('provider')->label(__('Portal'))->options(self::freeProviders())->required()
                    ->default(array_key_first(self::freeProviders())),
                ...self::fields(new IntegrationAccount),
            ])
            ->action(function (array $data): void {
                self::save((string) $data['provider'], $data);
                Notification::make()->title(__('Saved. Test the connection next.'))->success()->send();
            });
    }

    public static function edit(): Action
    {
        return Action::make('edit')
            ->label(__('Edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (IntegrationAccount $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->fillForm(fn (IntegrationAccount $record): array => [
                'client_id' => $record->credential('client_id'),
                'seller_id' => $record->credential('seller_id'),
                'remove_when_reserved' => (bool) $record->setting('remove_when_reserved', false),
                'send_vin' => (bool) $record->setting('send_vin', false),
                'is_active' => $record->is_active,
            ])
            ->schema(fn (IntegrationAccount $record): array => self::fields($record))
            ->action(function (IntegrationAccount $record, array $data): void {
                self::save($record->provider, $data);
                Notification::make()->title(__('Saved.'))->success()->send();
            });
    }

    public static function test(): Action
    {
        return Action::make('test')
            ->label(__('Test connection'))
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->visible(fn (IntegrationAccount $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (IntegrationAccount $record): void {
                $error = app(TestIntegration::class)($record);

                $error === null
                    ? Notification::make()->title(__('Connection works.'))->success()->send()
                    : Notification::make()->title(__('Connection failed'))->body($error)->danger()->persistent()->send();
            });
    }

    public static function syncAll(): Action
    {
        return Action::make('syncAll')
            ->label(__('Send all listings'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (IntegrationAccount $record): bool => $record->is_active && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('Every online listing is compared with the portal; changes are sent, sold or withdrawn cars are removed.'))
            ->action(function (IntegrationAccount $record): void {
                $count = app(ListingSync::class)->all($record);
                Notification::make()->title(__(':count listing(s) queued.', ['count' => $count]))->success()->send();
            });
    }

    public static function import(): Action
    {
        return Action::make('importStock')
            ->label(__('Import vehicles from the portal'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (IntegrationAccount $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription(__('Every car advertised there becomes a vehicle file "in review" with an advert draft and its photos. Cars already linked are skipped; a known VIN is linked, not created twice.'))
            ->action(function (IntegrationAccount $record): void {
                ImportPortalStockJob::dispatch($record->getKey());
                Notification::make()->title(__('Import started. The result appears in the log below.'))->success()->send();
            });
    }

    /**
     * @return array<int, mixed>
     */
    private static function fields(IntegrationAccount $record): array
    {
        $hasSecret = $record->exists && $record->credential('client_secret') !== null;

        return [
            Grid::make(2)->schema([
                TextInput::make('client_id')->label(__('Client ID'))->required()->maxLength(200),
                TextInput::make('client_secret')->label(__('Client secret'))->password()->revealable()->maxLength(500)
                    ->required(! $hasSecret)
                    ->helperText($hasSecret ? __('Stored. Leave empty to keep it.') : null),
                TextInput::make('seller_id')->label(__('Customer number'))->required()->maxLength(50)
                    ->helperText(__('Your AutoScout24 customer / seller number.')),
            ]),
            Toggle::make('remove_when_reserved')->label(__('Remove reserved cars from the portal'))
                ->helperText(__('Otherwise they stay online, marked as reserved.')),
            Toggle::make('send_vin')->label(__('Send the VIN')),
            Toggle::make('is_active')->label(__('Active'))
                ->helperText(__('Switched on, every published listing is sent to the portal and kept up to date.')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function save(string $provider, array $data): IntegrationAccount
    {
        return app(SaveIntegrationAccount::class)(
            $provider,
            ['client_id' => $data['client_id'] ?? null, 'client_secret' => $data['client_secret'] ?? null, 'seller_id' => $data['seller_id'] ?? null],
            ['remove_when_reserved' => (bool) ($data['remove_when_reserved'] ?? false), 'send_vin' => (bool) ($data['send_vin'] ?? false)],
            (bool) ($data['is_active'] ?? false),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function freeProviders(): array
    {
        $taken = IntegrationAccount::query()->pluck('provider')->all();

        return array_diff_key(Channels::options(), array_flip($taken));
    }
}
