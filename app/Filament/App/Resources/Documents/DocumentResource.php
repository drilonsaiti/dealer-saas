<?php

namespace App\Filament\App\Resources\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Filament\App\Resources\Documents\Pages\ListDocuments;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
        ];
    }
}
