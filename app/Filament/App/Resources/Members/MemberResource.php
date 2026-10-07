<?php

namespace App\Filament\App\Resources\Members;

use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Filament\App\Resources\Members\Pages\ManageMembers;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Settings → Users: who works in this dealer's account and with which role.
 */
class MemberResource extends Resource
{
    protected static ?string $model = TenantMembership::class;

    protected static ?string $tenantRelationshipName = 'memberships';

    protected static ?string $slug = 'users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

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
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    ->visibleOn(Operation::Create),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->maxLength(255)
                    ->visibleOn(Operation::Create),
                Select::make('role')
                    ->label(__('Role'))
                    ->options(Role::class)
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true)
                    ->visibleOn(Operation::Edit),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('user.email')->label(__('Email'))->searchable(),
                TextColumn::make('role')->label(__('Role'))->badge(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label(__('Remove')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMembers::route('/'),
        ];
    }
}
