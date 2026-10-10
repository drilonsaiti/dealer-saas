<?php

namespace App\Filament\App\Resources\IntegrationAccounts;

use App\Domain\Integrations\Actions\SaveIntegrationAccount;
use App\Domain\Integrations\Actions\TestIntegration;
use App\Domain\Integrations\Jobs\ImportPortalStockJob;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\ListingSync;
use App\Domain\Integrations\Support\Providers;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The actions of Settings → Integrations (connect/edit, test, send all, import).
 */
final class IntegrationActions
{
    public static function connect(): Action
    {
        return Action::make('connect')
            ->label(__('Connect service'))
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (): bool => (auth()->user()?->can('create', IntegrationAccount::class) ?? false)
                && self::freeProviders() !== [])
            ->schema(fn (): array => [
                Select::make('provider')->label(__('Service'))->options(self::freeProviders())->required()->live()
                    ->default(array_key_first(self::freeProviders())),
                ...self::fields(null),
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
                'provider' => $record->provider,
                ...collect(Providers::definition($record->provider)['fields'])
                    ->reject(fn (array $field): bool => $field['secret'] ?? false)
                    ->map(fn (array $field, string $key): ?string => $record->credential($key))->all(),
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
            ->visible(fn (IntegrationAccount $record): bool => $record->is_active && Providers::kind($record->provider) === Providers::LISTING && (auth()->user()?->can('update', $record) ?? false))
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
            ->visible(fn (IntegrationAccount $record): bool => Providers::kind($record->provider) === Providers::LISTING && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('Every car advertised there becomes a vehicle file "in review" with an advert draft and its photos. Cars already linked are skipped; a known VIN is linked, not created twice.'))
            ->action(function (IntegrationAccount $record): void {
                ImportPortalStockJob::dispatch($record->getKey());
                Notification::make()->title(__('Import started. The result appears in the log below.'))->success()->send();
            });
    }

    /**
     * The credential fields of every provider, each shown only for its provider (by the
     * "provider" field of the form). Secrets left empty keep the stored value.
     *
     * @return array<int, mixed>
     */
    private static function fields(?IntegrationAccount $record): array
    {
        $fields = [];

        foreach (Providers::all() as $provider => $definition) {
            foreach ($definition['fields'] as $key => $field) {
                $secret = $field['secret'] ?? false;
                $stored = $record?->provider === $provider && $record->credential($key) !== null;
                $input = TextInput::make($key)->label($field['label'])->maxLength(500)
                    ->visible(fn (Get $get): bool => $get('provider') === $provider)
                    ->required(fn (Get $get): bool => $get('provider') === $provider && ! ($secret && $stored))
                    ->helperText($secret && $stored ? __('Stored. Leave empty to keep it.') : ($field['help'] ?? null));
                $fields[] = $secret ? $input->password()->revealable() : $input;
            }
        }

        return [
            Hidden::make('provider')->visible($record !== null),
            Grid::make(2)->schema($fields),
            Toggle::make('remove_when_reserved')->label(__('Remove reserved cars from the portal'))
                ->helperText(__('Otherwise they stay online, marked as reserved.'))
                ->visible(fn (Get $get): bool => Providers::kind((string) $get('provider')) === Providers::LISTING),
            Toggle::make('send_vin')->label(__('Send the VIN'))
                ->visible(fn (Get $get): bool => Providers::kind((string) $get('provider')) === Providers::LISTING),
            Toggle::make('is_active')->label(__('Active'))
                ->helperText(fn (Get $get): string => Providers::kind((string) $get('provider')) === Providers::LISTING
                    ? __('Switched on, every published listing is sent to the portal and kept up to date.')
                    : __('Switched on, "Fetch vehicle data" appears in the vehicle file.')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function save(string $provider, array $data): IntegrationAccount
    {
        $credentials = [];

        foreach (array_keys(Providers::definition($provider)['fields']) as $key) {
            $credentials[$key] = $data[$key] ?? null;
        }

        return app(SaveIntegrationAccount::class)(
            $provider,
            $credentials,
            Providers::kind($provider) === Providers::LISTING
                ? ['remove_when_reserved' => (bool) ($data['remove_when_reserved'] ?? false), 'send_vin' => (bool) ($data['send_vin'] ?? false)]
                : [],
            (bool) ($data['is_active'] ?? false),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function freeProviders(): array
    {
        $taken = IntegrationAccount::query()->pluck('provider')->all();

        return array_diff_key(Providers::options(), array_flip($taken));
    }
}
