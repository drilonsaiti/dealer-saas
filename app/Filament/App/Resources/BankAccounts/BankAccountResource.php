<?php

namespace App\Filament\App\Resources\BankAccounts;

use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Rules\QrIban;
use App\Domain\Settings\Rules\SwissIban;
use App\Domain\Settings\Support\Iban;
use App\Filament\App\Resources\BankAccounts\Pages\ManageBankAccounts;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Bank account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Bank accounts');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('label')
                    ->label(__('Name'))
                    ->placeholder(__('e.g. Valiant main account'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('bank_name')->label(__('Bank'))->maxLength(255),
                TextInput::make('account_holder')
                    ->label(__('Account holder'))
                    ->helperText(__('Leave empty to use the company name.'))
                    ->maxLength(255),
                TextInput::make('bic')->label(__('BIC'))->maxLength(11),
                TextInput::make('iban')
                    ->label(__('IBAN'))
                    ->placeholder('CH00 0000 0000 0000 0000 0')
                    ->required()
                    ->rules([new SwissIban])
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : Iban::format($state))
                    ->dehydrateStateUsing(fn (string $state): string => Iban::normalize($state)),
                TextInput::make('qr_iban')
                    ->label(__('QR-IBAN'))
                    ->helperText(__('Optional. Issued by your bank on request. With a QR-IBAN, payments are matched by QR reference; without one, invoices use your IBAN with a SCOR reference.'))
                    ->nullable()
                    ->rules([new QrIban])
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : Iban::format($state))
                    ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : Iban::normalize($state)),
                Toggle::make('is_default')
                    ->label(__('Default account for invoices')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('bank_name')->label(__('Bank'))->toggleable(),
                TextColumn::make('iban')
                    ->label(__('IBAN'))
                    ->formatStateUsing(fn (string $state): string => Iban::format($state))
                    ->copyable(),
                TextColumn::make('qr_iban')
                    ->label(__('QR-IBAN'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '–' : Iban::format($state))
                    ->placeholder('–'),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
            ])
            ->defaultSort('label')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBankAccounts::route('/'),
        ];
    }
}
