<?php

namespace App\Filament\App\Resources\AccountingExports;

use App\Domain\Accounting\Actions\CancelAccountingExport;
use App\Domain\Accounting\Models\AccountingExport;
use App\Filament\App\Resources\AccountingExports\Pages\ManageAccountingExports;
use App\Support\BusinessRuleException;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance → Accounting export: journals for the accountant (CSV), each record only once;
 * the account numbers per booking type; undo the latest export.
 *
 * @extends resource<AccountingExport>
 */
class AccountingExportResource extends Resource
{
    protected static ?string $model = AccountingExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'accounting-exports';

    public static function getNavigationGroup(): string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Accounting export');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Accounting exports');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('Number'))->fontFamily('mono'),
                TextColumn::make('until_on')->label(__('Up to'))->date(),
                TextColumn::make('records_count')->label(__('Records')),
                TextColumn::make('entries_count')->label(__('Bookings')),
                TextColumn::make('total_rp')->label(__('Total'))->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd(),
                TextColumn::make('created_at')->label(__('Created'))->dateTime(),
                TextColumn::make('cancelled_at')->label(__('Status'))->badge()
                    ->state(fn (AccountingExport $record): string => $record->cancelled_at === null ? __('Exported') : __('Cancelled'))
                    ->color(fn (AccountingExport $record): string => $record->cancelled_at === null ? 'success' : 'gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label(__('Download (CSV)'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn (AccountingExport $record): bool => $record->document_id !== null)
                    ->action(function (AccountingExport $record): ?StreamedResponse {
                        $version = $record->document?->currentVersion;

                        return $version === null ? null : Storage::disk($version->disk)->download($version->path, $version->original_name);
                    }),
                Action::make('cancel')
                    ->label(__('Undo export'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->visible(fn (AccountingExport $record): bool => $record->cancelled_at === null && (auth()->user()?->can('cancel', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(__('Only if the accountant has not imported the file: its records are exported again next time. The file stays in the archive, marked cancelled.'))
                    ->action(function (AccountingExport $record, Action $action): void {
                        try {
                            app(CancelAccountingExport::class)($record);
                            Notification::make()->title(__('Export cancelled.'))->success()->send();
                        } catch (BusinessRuleException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('document.currentVersion'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageAccountingExports::route('/')];
    }
}
