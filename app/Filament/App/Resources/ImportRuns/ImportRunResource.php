<?php

namespace App\Filament\App\Resources\ImportRuns;

use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Import\Models\ImportRun;
use App\Filament\App\Resources\ImportRuns\Pages\CreateImportRun;
use App\Filament\App\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\App\Resources\ImportRuns\Pages\ViewImportRun;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Settings → Import: bring vehicles, costs and document folders from Excel or another tool.
 *
 * @extends resource<ImportRun>
 */
class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static ?string $slug = 'imports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpOnSquareStack;

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Import');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Imports');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Import'))->schema([
                Grid::make(4)->schema([
                    TextEntry::make('importer')->label(__('Type')),
                    TextEntry::make('file_name')->label(__('Import file')),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('created_at')->label(__('Started'))->dateTime(),
                    ...collect(ImportRowAction::cases())->map(fn (ImportRowAction $action): TextEntry => TextEntry::make('summary.'.$action->value)
                        ->label($action->getLabel())
                        ->placeholder('0')
                        ->color($action->getColor()))->all(),
                    TextEntry::make('summary.total')->label(__('Rows'))->placeholder('0'),
                ]),
                TextEntry::make('error')->label(__('Error'))->color('danger')->visible(fn (ImportRun $record): bool => filled($record->error)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('Started'))->dateTime()->sortable(),
                TextColumn::make('importer')->label(__('Type')),
                TextColumn::make('file_name')->label(__('Import file'))->wrap(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('summary.total')->label(__('Rows'))->placeholder('–'),
                TextColumn::make('summary.error')->label(__('Errors'))->placeholder('0')->color(fn (?int $state): ?string => ($state ?? 0) > 0 ? 'danger' : null),
                TextColumn::make('creator.name')->label(__('User'))->placeholder('–'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (ImportRun $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'create' => CreateImportRun::route('/create'),
            'view' => ViewImportRun::route('/{record}'),
        ];
    }
}
