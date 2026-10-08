<?php

namespace App\Filament\App\Resources\ImportRuns\Pages;

use App\Domain\Import\Actions\RollbackImport;
use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Import\Jobs\ProcessImportRun;
use App\Domain\Import\Models\ImportRow;
use App\Domain\Import\Models\ImportRun;
use App\Filament\App\Resources\ImportRuns\ImportRunResource;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The report of one import: what each row did (or would do), then import or roll back.
 */
class ViewImportRun extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ImportRunResource::class;

    protected string $view = 'filament.app.import-run';

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof ImportRun ? $record->file_name : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('commit')
                ->label(__('Import now'))
                ->icon(Heroicon::OutlinedArrowDownOnSquare)
                ->visible(fn (ImportRun $record): bool => $record->status === ImportRunStatus::Checked)
                ->requiresConfirmation()
                ->modalDescription(fn (ImportRun $record): string => ($record->summary['error'] ?? 0) > 0
                    ? __('Rows with errors are skipped; all other rows are imported. You can roll the import back afterwards.')
                    : __('All rows are imported as checked. You can roll the import back afterwards.'))
                ->action(function (ImportRun $record): void {
                    ProcessImportRun::dispatch($record->getKey(), commit: true);
                    Notification::make()->title(__('Import started.'))->success()->send();
                    $record->refresh();
                }),
            Action::make('recheck')
                ->label(__('Check again'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (ImportRun $record): bool => in_array($record->status, [ImportRunStatus::Checked, ImportRunStatus::Failed], true))
                ->action(function (ImportRun $record): void {
                    ProcessImportRun::dispatch($record->getKey(), commit: false);
                    $record->refresh();
                }),
            Action::make('rollback')
                ->label(__('Roll back'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->visible(fn (ImportRun $record): bool => $record->status === ImportRunStatus::Committed)
                ->requiresConfirmation()
                ->modalDescription(__('Removes what this import created. Records that were changed by hand since then are kept.'))
                ->action(function (ImportRun $record, Action $action): void {
                    try {
                        $result = app(RollbackImport::class)($record);
                    } catch (BusinessRuleException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title(__('Rolled back: :removed removed, :kept kept.', $result))
                        ->success()
                        ->send();
                    $record->refresh();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ImportRow::query()->where('import_run_id', $this->getRecord()->getKey()))
            ->poll(fn (): ?string => $this->getRecord() instanceof ImportRun && $this->getRecord()->refresh()->status->isBusy() ? '2s' : null)
            ->columns([
                TextColumn::make('row_ref')->label(__('Row'))->wrap(),
                TextColumn::make('action')->label(__('Result'))->badge(),
                TextColumn::make('messages')->label(__('Notes'))->listWithLineBreaks()->wrap()->placeholder('–'),
            ])
            ->defaultSort('position')
            ->filters([
                SelectFilter::make('action')->label(__('Result'))->options(ImportRowAction::class),
            ])
            ->paginated([25, 50, 100]);
    }
}
