<?php

namespace App\Filament\App\Resources\ApiTokens;

use App\Domain\Api\Actions\IssueApiToken;
use App\Domain\Api\Models\ApiToken;
use App\Filament\App\Resources\ApiTokens\Pages\ManageApiTokens;
use App\Support\BusinessRuleException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Settings → API tokens: for the website plugin or the dealer's own website. The token is
 * shown once when created.
 *
 * @extends resource<ApiToken>
 */
class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'api-tokens';

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('API token');
    }

    public static function getPluralModelLabel(): string
    {
        return __('API tokens');
    }

    /**
     * @return array<string, string>
     */
    public static function abilityOptions(): array
    {
        return ['listings:read' => __('Read published vehicles'), 'enquiries:write' => __('Send enquiries')];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->description(fn (ApiToken $record): string => $record->token_prefix.'…'),
                TextColumn::make('abilities')->label(__('Permissions'))->badge()
                    ->formatStateUsing(fn (string $state): string => self::abilityOptions()[$state] ?? $state),
                TextColumn::make('last_used_at')->label(__('Last used'))->since()->placeholder(__('never')),
                TextColumn::make('expires_at')->label(__('Valid until'))->date()->placeholder(__('no expiry')),
                TextColumn::make('revoked_at')->label(__('Status'))->badge()
                    ->state(fn (ApiToken $record): string => $record->isUsable() ? __('Active') : __('Revoked'))
                    ->color(fn (ApiToken $record): string => $record->isUsable() ? 'success' : 'danger'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (ApiToken $record): bool => $record->revoked_at === null && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(__('Websites using this token stop working at once.'))
                    ->action(fn (ApiToken $record) => app(IssueApiToken::class)->revoke($record)),
            ])
            ->paginated(false);
    }

    public static function create(): Action
    {
        return Action::make('createToken')
            ->label(__('New API token'))
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => auth()->user()?->can('create', ApiToken::class) ?? false)
            ->schema([
                TextInput::make('name')->label(__('Name'))->placeholder(__('e.g. Website'))->required()->maxLength(100),
                CheckboxList::make('abilities')->label(__('Permissions'))->options(self::abilityOptions())->default(array_keys(self::abilityOptions()))->required(),
                DatePicker::make('expires_at')->label(__('Valid until'))->minDate(now()->addDay()),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    [, $plain] = app(IssueApiToken::class)((string) $data['name'], array_values($data['abilities']), $data['expires_at'] ?? null);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('Copy the token now; it is not shown again.'))
                    ->body($plain)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageApiTokens::route('/'),
        ];
    }
}
