<?php

namespace App\Filament\App\Resources\Mailboxes;

use App\Domain\Inbox\Actions\FetchMailbox;
use App\Domain\Inbox\Actions\SaveMailbox;
use App\Domain\Inbox\Models\Mailbox;
use App\Filament\App\Resources\Mailboxes\Pages\ManageMailboxes;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Settings → Mailboxes: the dealer's addresses fetched into the inbox (IMAP) and used for
 * replies (SMTP). Passwords are never shown again.
 *
 * @extends resource<Mailbox>
 */
class MailboxResource extends Resource
{
    protected static ?string $model = Mailbox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 63;

    protected static ?string $slug = 'mailboxes';

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Mailbox');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Mailboxes');
    }

    /**
     * @return array<int, mixed>
     */
    public static function fields(?Mailbox $record): array
    {
        $encryption = ['ssl' => __('SSL/TLS'), 'tls' => 'STARTTLS', 'none' => __('None')];
        $hasImap = $record?->secret('imap_password') !== null;
        $hasSmtp = $record?->secret('smtp_password') !== null;

        return [
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(100)->placeholder(__('e.g. Sales')),
                TextInput::make('email')->label(__('E-mail address'))->email()->required()->maxLength(200),
            ]),
            Section::make(__('Incoming (IMAP)'))->schema([
                Grid::make(4)->schema([
                    TextInput::make('imap_host')->label(__('Server'))->required()->maxLength(200)->columnSpan(2),
                    TextInput::make('imap_port')->label(__('Port'))->numeric()->required()->default(993),
                    Select::make('imap_encryption')->label(__('Encryption'))->options($encryption)->required()->default('ssl'),
                    TextInput::make('imap_username')->label(__('User name'))->required()->maxLength(200)->columnSpan(2),
                    TextInput::make('imap_password')->label(__('Password'))->password()->revealable()->required(! $hasImap)
                        ->helperText($hasImap ? __('Stored. Leave empty to keep it.') : null),
                    TextInput::make('imap_folder')->label(__('Folder'))->required()->default('INBOX')->maxLength(200),
                ]),
            ]),
            Section::make(__('Outgoing (SMTP)'))->description(__('Needed to send replies from this address.'))->schema([
                Grid::make(4)->schema([
                    TextInput::make('smtp_host')->label(__('Server'))->maxLength(200)->columnSpan(2),
                    TextInput::make('smtp_port')->label(__('Port'))->numeric()->placeholder('587'),
                    Select::make('smtp_encryption')->label(__('Encryption'))->options($encryption)->default('tls'),
                    TextInput::make('smtp_username')->label(__('User name'))->maxLength(200)->columnSpan(2)
                        ->helperText(__('Empty: same as incoming.')),
                    TextInput::make('smtp_password')->label(__('Password'))->password()->revealable()
                        ->helperText($hasSmtp ? __('Stored. Leave empty to keep it.') : __('Empty: same as incoming.')),
                ]),
            ]),
            Toggle::make('is_active')->label(__('Fetch automatically (every 5 minutes)'))->default(true),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->description(fn (Mailbox $record): string => $record->email),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('last_fetched_at')->label(__('Last fetched'))->since()->placeholder('–'),
                TextColumn::make('last_error')->label(__('Last error'))->limit(60)->color('danger')->placeholder('–'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Mailbox $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->fillForm(fn (Mailbox $record): array => $record->only(['name', 'email', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_folder', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'is_active']))
                    ->schema(fn (Mailbox $record): array => self::fields($record))
                    ->modalWidth('3xl')
                    ->action(function (Mailbox $record, array $data): void {
                        app(SaveMailbox::class)($record, $data);
                        Notification::make()->title(__('Saved.'))->success()->send();
                    }),
                Action::make('test')
                    ->label(__('Test connection'))
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->action(function (Mailbox $record): void {
                        $error = app(FetchMailbox::class)->test($record);
                        $error === null
                            ? Notification::make()->title(__('Connection works.'))->success()->send()
                            : Notification::make()->title(__('Connection failed'))->body($error)->danger()->persistent()->send();
                    }),
                Action::make('fetch')
                    ->label(__('Fetch now'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(function (Mailbox $record): void {
                        $result = app(FetchMailbox::class)($record);
                        $result['error'] === null
                            ? Notification::make()->title(__(':count new e-mail(s).', ['count' => $result['new']]))->success()->send()
                            : Notification::make()->title(__('Fetching failed'))->body($result['error'])->danger()->persistent()->send();
                    }),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMailboxes::route('/')];
    }
}
