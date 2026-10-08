<?php

namespace App\Filament\App\Resources\CostCategories;

use App\Domain\Purchasing\Models\CostCategory;
use App\Filament\App\Resources\CostCategories\Pages\ManageCostCategories;
use BackedEnum;
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
use Illuminate\Support\Str;

/**
 * Settings → Cost categories: names in all four languages; deactivate instead of delete.
 *
 * @extends resource<CostCategory>
 */
class CostCategoryResource extends Resource
{
    protected static ?string $model = CostCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Cost category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Cost categories');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name.de')->label(__('Name (German)'))->required()->maxLength(80),
                TextInput::make('name.fr')->label(__('Name (French)'))->required()->maxLength(80),
                TextInput::make('name.it')->label(__('Name (Italian)'))->required()->maxLength(80),
                TextInput::make('name.en')->label(__('Name (English)'))->required()->maxLength(80),
                TextInput::make('sort')->label(__('Order'))->integer()->default(100)->required(),
                Toggle::make('counts_toward_margin')->label(__('Counts toward the vehicle margin'))->default(true),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                IconColumn::make('counts_toward_margin')->label(__('In margin'))->boolean(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('sort')->label(__('Order'))->sortable(),
            ])
            ->defaultSort('sort')
            ->paginated(false)
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (CostCategory $record): array => [
                        ...$record->attributesToArray(),
                        'name' => $record->getTranslations('name'),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withKey(array $data): array
    {
        $data['key'] ??= Str::slug((string) ($data['name']['en'] ?? $data['name']['de'] ?? 'category'), '_').'_'.Str::lower(Str::random(4));

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCostCategories::route('/'),
        ];
    }
}
