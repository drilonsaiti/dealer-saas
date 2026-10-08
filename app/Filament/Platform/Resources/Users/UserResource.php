<?php

namespace App\Filament\Platform\Resources\Users;

use App\Filament\Platform\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Platform → Users: all users across dealers.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('email')->label(__('Email'))->email()->required()->unique(ignoreRecord: true),
                Select::make('locale')->label(__('Language'))->options(config('dealer.locale_names'))->placeholder(__('Automatic (language of the dealer)')),
                Toggle::make('is_platform_admin')->label(__('Platform administrator')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('Email'))->searchable(),
                TextColumn::make('tenants.name')->label(__('Dealers'))->badge()->listWithLineBreaks(),
                TextColumn::make('locale')->label(__('Language')),
                IconColumn::make('is_platform_admin')->label(__('Platform administrator'))->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
