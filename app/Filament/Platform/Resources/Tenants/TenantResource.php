<?php

namespace App\Filament\Platform\Resources\Tenants;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Platform\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Platform\Resources\Tenants\Pages\EditTenant;
use App\Filament\Platform\Resources\Tenants\Pages\ListTenants;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Platform → Dealers: create and manage tenants.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $slug = 'dealers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Dealer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Dealers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Dealer'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->label(__('Display name'))->required()->maxLength(255),
                    TextInput::make('slug')
                        ->label(__('URL name'))
                        ->helperText(__('Leave empty to generate it from the name.'))
                        ->alphaDash()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true),
                    TextInput::make('legal_name')->label(__('Legal name'))->maxLength(255),
                    TextInput::make('city')->label(__('City'))->maxLength(255),
                    Select::make('default_locale')
                        ->label(__('Default language'))
                        ->options(config('dealer.locale_names'))
                        ->default('de')
                        ->required(),
                    Select::make('status')
                        ->label(__('Status'))
                        ->options([
                            Tenant::STATUS_ACTIVE => __('Active'),
                            Tenant::STATUS_SUSPENDED => __('Suspended'),
                        ])
                        ->default(Tenant::STATUS_ACTIVE)
                        ->required(),
                ]),
            ]),
            Section::make(__('First administrator'))
                ->description(__('Receives an email with a link to set a password.'))
                ->visibleOn(Operation::Create)
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('admin_email')->label(__('Email'))->email()->required(),
                        TextInput::make('admin_name')->label(__('Name'))->maxLength(255),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('URL name'))->fontFamily('mono'),
                TextColumn::make('city')->label(__('City'))->toggleable(),
                TextColumn::make('memberships_count')->counts('memberships')->label(__('Users')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('created_at')->label(__('Created'))->date('d.m.Y')->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}
