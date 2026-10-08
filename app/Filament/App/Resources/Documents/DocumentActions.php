<?php

namespace App\Filament\App\Resources\Documents;

use App\Domain\Documents\Actions\AddDocumentVersion;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Actions\MergeDocuments;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Documents\Jobs\RunOcr;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Support\DocumentFileName;
use App\Domain\Documents\Support\DuplicateDocument;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document actions shared by the vehicle file and the documents screen.
 * They collect input and call the domain actions; files never pass through Filament's storage.
 */
final class DocumentActions
{
    public static function canSeeSensitive(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::DocumentsViewSensitive);
    }

    /**
     * @param  callable(): list<Model>  $links  records the new documents belong to
     */
    public static function upload(callable $links): Action
    {
        return Action::make('upload')
            ->label(__('Upload documents'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => auth()->user()?->can('create', Document::class) ?? false)
            ->modalWidth('3xl')
            ->schema([
                FileUpload::make('files')
                    ->label(__('Files'))
                    ->helperText(__('PDF or photos. On a phone or iPad you can take a photo directly.'))
                    ->multiple()
                    ->storeFiles(false)
                    ->maxSize((int) config('dealer.documents.max_upload_kb'))
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp', 'image/tiff'])
                    ->extraInputAttributes(['capture' => 'environment'])
                    ->required(),
                Grid::make(3)->schema([
                    Select::make('category_id')
                        ->label(__('Category'))
                        ->options(fn (): array => DocumentCategory::groupedOptions(self::canSeeSensitive()))
                        ->searchable()
                        ->required()
                        ->columnSpan(2),
                    DatePicker::make('document_on')->label(__('Document date')),
                    TextInput::make('title')->label(__('Title'))->helperText(__('Leave empty to use category and date.'))->maxLength(255)->columnSpan(2),
                    Select::make('locale')->label(__('Language'))->options(config('dealer.locale_names')),
                ]),
            ])
            ->action(function (array $data) use ($links): void {
                $category = DocumentCategory::query()->findOrFail($data['category_id']);
                $stored = 0;
                $skipped = [];

                foreach ((array) $data['files'] as $file) {
                    if (! $file instanceof TemporaryUploadedFile) {
                        continue;
                    }

                    try {
                        app(StoreDocument::class)($file->getRealPath(), $category, [
                            'title' => $data['title'] ?? null,
                            'document_on' => $data['document_on'] ?? null,
                            'locale' => $data['locale'] ?? null,
                            'original_name' => $file->getClientOriginalName(),
                        ], $links());
                        $stored++;
                    } catch (BusinessRuleException $e) {
                        $skipped[] = $file->getClientOriginalName().': '.$e->getMessage();
                    }
                }

                if ($stored > 0) {
                    Notification::make()->title(__(':count document(s) stored.', ['count' => $stored]))->success()->send();
                }

                if ($skipped !== []) {
                    Notification::make()->title(__('Not stored'))->body(implode("\n", $skipped))->warning()->persistent()->send();
                }
            });
    }

    public static function open(): Action
    {
        return Action::make('open')
            ->label(__('Open document'))
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (Document $record): bool => $record->currentVersion !== null)
            ->url(fn (Document $record): ?string => $record->currentVersion === null ? null
                : Storage::disk($record->currentVersion->disk)->temporaryUrl($record->currentVersion->path, now()->addMinutes(5)))
            ->openUrlInNewTab();
    }

    public static function download(): Action
    {
        return Action::make('download')
            ->label(__('Download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (Document $record): bool => $record->currentVersion !== null)
            ->action(function (Document $record): StreamedResponse {
                $version = $record->currentVersion;

                return Storage::disk($version->disk)->download($version->path, DocumentFileName::forDownload($record, $version));
            });
    }

    /**
     * Reads the text of a scan or photo right now, without waiting for the queue worker.
     */
    public static function recognizeText(): Action
    {
        return Action::make('recognizeText')
            ->label(__('Read text now'))
            ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->visible(fn (Document $record): bool => in_array($record->currentVersion?->ocr_status, [OcrStatus::Pending, OcrStatus::Failed], true)
                && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (Document $record): void {
                $version = $record->currentVersion;

                if ($version === null) {
                    return;
                }

                $version->forceFill(['ocr_status' => OcrStatus::Pending])->save();
                app()->call([new RunOcr($version->getKey()), 'handle']); // here and now, not through the queue

                $status = $version->refresh()->ocr_status;

                Notification::make()
                    ->title($status === OcrStatus::Done ? __('The text was read; the document is now searchable.') : __('The text could not be read. Is Tesseract installed?'))
                    ->{$status === OcrStatus::Done ? 'success' : 'danger'}()
                    ->send();
            });
    }

    public static function newVersion(): Action
    {
        return Action::make('newVersion')
            ->label(__('Replace file'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalDescription(__('The new file becomes the current version; earlier versions are kept.'))
            // Generated contracts get new versions only by finalising or signing them.
            ->visible(fn (Document $record): bool => $record->type_key === null && (auth()->user()?->can('update', $record) ?? false))
            ->schema([
                FileUpload::make('file')->label(__('File'))->storeFiles(false)->maxSize((int) config('dealer.documents.max_upload_kb'))->required(),
            ])
            ->action(function (Document $record, array $data, Action $action): void {
                $file = $data['file'];

                if (! $file instanceof TemporaryUploadedFile) {
                    return;
                }

                try {
                    app(AddDocumentVersion::class)($record, $file->getRealPath(), $file->getClientOriginalName());
                } catch (DuplicateDocument|BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title(__('New version stored.'))->success()->send();
            });
    }

    public static function merge(): Action
    {
        return Action::make('merge')
            ->label(__('Merge duplicate'))
            ->icon(Heroicon::OutlinedSquare2Stack)
            ->color('warning')
            ->visible(fn (Document $record): bool => $record->possible_duplicate_of_id !== null && (auth()->user()?->can('update', $record) ?? false))
            ->modalDescription(fn (Document $record): string => __('This looks like the same document as ":title". Keep one as the main document; the other becomes an earlier version of it.', ['title' => $record->possibleDuplicateOf->title ?? '']))
            ->schema(fn (Document $record): array => [
                Select::make('main')->label(__('Main document'))->options([
                    $record->possible_duplicate_of_id => $record->possibleDuplicateOf->title ?? '',
                    $record->getKey() => $record->title.' ('.__('this one').')',
                ])->default($record->possible_duplicate_of_id)->required(),
            ])
            ->action(function (Document $record, array $data, Action $action): void {
                $other = $record->possibleDuplicateOf;

                if ($other === null) {
                    return;
                }

                [$main, $duplicate] = $data['main'] === $record->getKey() ? [$record, $other] : [$other, $record];

                try {
                    app(MergeDocuments::class)($main, $duplicate);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title(__('Documents merged.'))->success()->send();
            });
    }

    public static function edit(): EditAction
    {
        return EditAction::make()
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('title')->label(__('Title'))->required()->maxLength(255)->columnSpan(2),
                    Select::make('category_id')->label(__('Category'))->options(fn (): array => DocumentCategory::groupedOptions(self::canSeeSensitive()))->required(),
                    DatePicker::make('document_on')->label(__('Document date')),
                    Select::make('locale')->label(__('Language'))->options(config('dealer.locale_names')),
                ]),
            ]);
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->using(function (Document $record): bool {
                try {
                    app(DeleteDocument::class)($record);

                    return true;
                } catch (BusinessRuleException) {
                    return false;
                }
            });
    }
}
