<?php

namespace App\Filament\App\Resources\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Documents\Pages\ListDocuments;
use App\Filament\App\Resources\StockCycles\RelationManagers\DocumentsRelationManager;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * All documents, searchable by title, file name and recognised text; also the inbox of
 * documents that are not assigned to any vehicle file or contact yet.
 *
 * @extends resource<Document>
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('Document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Documents');
    }

    public static function table(Table $table): Table
    {
        return DocumentTable::configure($table, groupByFolder: false)
            ->filters([
                SelectFilter::make('folder')
                    ->label(__('Folder'))
                    ->options(DocumentTable::folderOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('category', fn (Builder $category) => $category->where('folder_group', $data['value']))
                        : $query),
                SelectFilter::make('category_id')->label(__('Category'))->options(fn (): array => DocumentCategory::groupedOptions(DocumentActions::canSeeSensitive())),
                TernaryFilter::make('assigned')
                    ->label(__('Assignment'))
                    ->trueLabel(__('Assigned'))
                    ->falseLabel(__('Not assigned (inbox)'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('links'),
                        false: fn (Builder $query) => $query->whereDoesntHave('links'),
                    ),
            ])
            ->headerActions([
                DocumentActions::upload(fn (): array => []),
            ]);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title'];
    }

    /**
     * Global search looks into the recognised text too, so "Kaufvertrag Corolla" finds the contract.
     *
     * @param  Builder<Document>  $query
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $query->visibleTo(DocumentActions::canSeeSensitive());
        DocumentTable::search($query, $search);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['category', 'currentVersion', 'stockCycles.vehicle']);
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Document $record */
        return $record->title;
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Document $record */
        return array_filter([
            __('Vehicle file') => $record->stockCycles->map(fn (StockCycle $cycle): string => $cycle->title())->implode(', '),
            __('Category') => $record->category->name,
        ]);
    }

    /**
     * The documents tab of the vehicle file, or the documents list for unassigned ones.
     */
    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        /** @var Document $record */
        $cycle = $record->stockCycles->first();

        if ($cycle !== null && StockCycleResource::canView($cycle)) {
            $tab = array_search(DocumentsRelationManager::class, StockCycleResource::getRelations(), true);

            return StockCycleResource::getUrl('view', ['record' => $cycle, 'relation' => (string) $tab]);
        }

        return static::getUrl('index', ['search' => $record->title]);
    }

    /**
     * @return array<Action>
     */
    public static function getGlobalSearchResultActions(Model $record): array
    {
        /** @var Document $record */
        $version = $record->currentVersion;

        if ($version === null) {
            return [];
        }

        return [
            Action::make('open')
                ->label(__('Open document'))
                ->url(Storage::disk($version->disk)->temporaryUrl($version->path, now()->addMinutes(30)), shouldOpenInNewTab: true),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
        ];
    }
}
