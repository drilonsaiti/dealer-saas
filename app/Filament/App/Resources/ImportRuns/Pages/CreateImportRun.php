<?php

namespace App\Filament\App\Resources\ImportRuns\Pages;

use App\Domain\Import\Actions\CreateImportRun as CreateRun;
use App\Domain\Import\Actions\RunImport;
use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Importers\DocumentFolderImporter;
use App\Domain\Import\Jobs\ProcessImportRun;
use App\Domain\Import\Models\ImportPreset;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Filament\App\Resources\ImportRuns\ImportRunResource;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * New import: file, type, and which column holds what (recognised automatically where
 * possible, saved as a preset for next time). Saving starts the check (dry run).
 */
class CreateImportRun extends CreateRecord
{
    protected static string $resource = ImportRunResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('New import');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Import file'))->schema([
                Grid::make(2)->schema([
                    Select::make('importer')
                        ->label(__('What do you import?'))
                        ->options(ImporterType::class)
                        ->default(ImporterType::Vehicles->value)
                        ->live()
                        ->required(),
                    Select::make('preset_id')
                        ->label(__('Saved mapping'))
                        ->options(fn (Get $get): array => ImportPreset::query()->where('importer', $get('importer'))->pluck('name', 'id')->all())
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $preset = $state === null ? null : ImportPreset::query()->find($state);

                            if ($preset !== null) {
                                $set('mapping', $preset->mapping);
                                $set('sheet', $preset->options['sheet'] ?? null);
                                $set('pattern', $preset->options['pattern'] ?? null);
                            }
                        })
                        ->visible(fn (Get $get): bool => ImportPreset::query()->where('importer', $get('importer'))->exists()),
                ]),
                FileUpload::make('file')
                    ->label(__('Import file'))
                    ->helperText(fn (Get $get): string => $this->isZip($get)
                        ? __('ZIP of your document folders. Very large folders can also be imported on the server with "php artisan import:run".')
                        : __('Excel (.xlsx, .xlsm) or CSV. The first row must contain the column names.'))
                    ->storeFiles(false)
                    ->maxSize(204800)
                    ->live()
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => $this->guessMapping($state, $get, $set))
                    ->required(),
                Grid::make(2)->schema([
                    Select::make('sheet')
                        ->label(__('Sheet'))
                        ->options(fn (Get $get): array => $this->sheets($get))
                        ->visible(fn (Get $get): bool => ! $this->isZip($get) && count($this->sheets($get)) > 1)
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->guessMapping($get('file'), $get, $set)),
                    TextInput::make('pattern')
                        ->label(__('File name pattern'))
                        ->default(DocumentFolderImporter::DEFAULT_PATTERN)
                        ->helperText(__('Placeholders: {date}, {stammnummer}, {type}, {id}. Example: 2026-07-14_683737537_Kaufvertrag_D0028.pdf'))
                        ->visible(fn (Get $get): bool => $this->isZip($get)),
                ]),
            ]),
            Section::make(__('Columns'))
                ->description(__('Which column holds what. Recognised columns are filled in; check them before you continue.'))
                ->visible(fn (Get $get): bool => ! $this->isZip($get) && $this->headers($get) !== [])
                ->schema(fn (Get $get): array => [
                    Grid::make(3)->schema(collect(RunImport::importer($this->type($get))->fields())
                        ->map(fn (string $label, string $field): Select => Select::make('mapping.'.$field)
                            ->label($label)
                            ->options(fn (Get $get): array => array_combine($this->headers($get), $this->headers($get)))
                            ->placeholder(__('not in the file')))
                        ->values()
                        ->all()),
                    TextInput::make('save_preset_as')->label(__('Save this mapping as'))->placeholder(__('e.g. My Excel'))->maxLength(80),
                ]),
            Text::make(__('Nothing is changed yet: saving checks the file and shows what the import would do.'))->color('gray'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $file = $this->uploaded($data['file'] ?? null);
        $type = $data['importer'] instanceof ImporterType ? $data['importer'] : ImporterType::from((string) $data['importer']);

        $run = app(CreateRun::class)(
            (string) $file?->getRealPath(),
            (string) $file?->getClientOriginalName(),
            $type,
            $type === ImporterType::Documents ? [] : array_filter((array) ($data['mapping'] ?? [])),
            array_filter(['sheet' => $data['sheet'] ?? null, 'pattern' => $type === ImporterType::Documents ? ($data['pattern'] ?? null) : null]),
            $data['save_preset_as'] ?? null,
        );

        ProcessImportRun::dispatch($run->getKey(), commit: false);

        return $run;
    }

    protected function getRedirectUrl(): string
    {
        return ImportRunResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function type(Get $get): ImporterType
    {
        $value = $get('importer');

        return $value instanceof ImporterType ? $value : ImporterType::tryFrom((string) $value) ?? ImporterType::Vehicles;
    }

    private function isZip(Get $get): bool
    {
        return $this->type($get) === ImporterType::Documents;
    }

    private function uploaded(mixed $state): ?TemporaryUploadedFile
    {
        if ($state instanceof TemporaryUploadedFile) {
            return $state;
        }

        if (is_array($state)) {
            $first = reset($state);

            return $first instanceof TemporaryUploadedFile ? $first : null;
        }

        return null;
    }

    private function reader(Get $get): ?SpreadsheetReader
    {
        $file = $this->uploaded($get('file'));

        if ($file === null || $this->isZip($get)) {
            return null;
        }

        return new SpreadsheetReader($file->getRealPath(), $file->getClientOriginalName());
    }

    /**
     * @return array<string, string>
     */
    private function sheets(Get $get): array
    {
        try {
            $names = $this->reader($get)?->sheetNames() ?? [];
        } catch (Throwable) {
            return [];
        }

        return array_combine($names, $names);
    }

    /**
     * @return list<string>
     */
    private function headers(Get $get): array
    {
        try {
            return $this->reader($get)?->headers($get('sheet')) ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    private function guessMapping(mixed $state, Get $get, Set $set): void
    {
        if ($this->isZip($get) || filled($get('preset_id'))) {
            return;
        }

        $set('mapping', CreateRun::guessMapping(RunImport::importer($this->type($get))->guesses(), $this->headers($get)));
    }
}
