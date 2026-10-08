<?php

namespace App\Filament\App\Resources\Documents;

use App\Domain\Documents\Enums\FolderGroup;
use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Vehicles\Models\StockCycle;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Columns and row actions shared by the vehicle file's documents tab and the documents screen.
 */
final class DocumentTable
{
    public static function configure(Table $table, bool $groupByFolder): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->when(! DocumentActions::canSeeSensitive(), fn (Builder $hidden) => $hidden
                    ->whereHas('category', fn (Builder $category) => $category->where('sensitive', false)))
                ->with(['category', 'currentVersion', 'possibleDuplicateOf', 'links'])
                ->withCount('versions'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->description(fn (Document $record): string => $record->category->name.' · '.($record->currentVersion !== null ? $record->currentVersion->original_name : ''))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search)),
                TextColumn::make('document_on')->label(__('Date'))->date()->placeholder('–')->sortable(),
                TextColumn::make('versions_count')->label(__('Versions'))->alignCenter(),
                TextColumn::make('currentVersion.ocr_status')
                    ->label(__('Text'))
                    ->badge()
                    ->formatStateUsing(fn (?OcrStatus $state): string => $state?->getLabel() ?? '–')
                    ->color(fn (?OcrStatus $state): string => $state?->getColor() ?? 'gray'),
                TextColumn::make('possible_duplicate_of_id')
                    ->label(__('Duplicate?'))
                    ->state(fn (Document $record): ?string => $record->possible_duplicate_of_id === null ? null : __('Possible duplicate'))
                    ->badge()
                    ->color('warning')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->tooltip(fn (Document $record): ?string => $record->possibleDuplicateOf === null ? null : __('Possibly the same as ":title"', ['title' => $record->possibleDuplicateOf->title])),
                TextColumn::make('linked_files')
                    ->label(__('Vehicle file'))
                    ->visible(! $groupByFolder)
                    ->state(fn (Document $record): array => StockCycle::query()
                        ->with('vehicle')
                        ->whereIn('id', $record->links->where('linkable_type', 'stock_cycle')->pluck('linkable_id'))
                        ->get()
                        ->map(fn (StockCycle $cycle): string => $cycle->title())
                        ->all())
                    ->placeholder(__('Not assigned'))
                    ->listWithLineBreaks(),
            ])
            ->groups([
                Group::make('category.folder_group')
                    ->label(__('Folder'))
                    ->getTitleFromRecordUsing(fn (Document $record): string => $record->category->folder_group->folderName())
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query
                        ->join('document_categories as dc_sort', 'dc_sort.id', '=', 'documents.category_id')
                        ->orderBy('dc_sort.folder_group', $direction)
                        ->select('documents.*')),
            ])
            ->defaultGroup($groupByFolder ? 'category.folder_group' : null)
            ->defaultSort('document_on', 'desc')
            ->recordActions([
                DocumentActions::merge(),
                DocumentActions::open(),
                ActionGroup::make([
                    DocumentActions::download(),
                    DocumentActions::recognizeText(),
                    DocumentActions::newVersion(),
                    DocumentActions::edit(),
                    DocumentActions::delete(),
                ]),
            ]);
    }

    /**
     * Title, file name, and the recognised text (full-text search).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public static function search(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower(trim($search)).'%';

        return $query->where(function (Builder $where) use ($term, $search): void {
            $where->whereRaw('lower(documents.title) like ?', [$term])
                ->orWhereHas('versions', fn (Builder $versions) => $versions
                    ->whereRaw('lower(original_name) like ?', [$term])
                    ->orWhereRaw("search_vector @@ plainto_tsquery('simple', ?)", [$search]));
        });
    }

    /**
     * @return array<string, string>
     */
    public static function folderOptions(): array
    {
        return collect(FolderGroup::cases())->mapWithKeys(fn (FolderGroup $folder): array => [$folder->value => $folder->folderName()])->all();
    }
}
