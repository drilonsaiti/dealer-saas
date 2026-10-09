<?php

namespace App\Filament\App\Resources\VatPeriods;

use App\Domain\Documents\Models\Document;
use App\Domain\Vat\Actions\CloseVatPeriod;
use App\Domain\Vat\Actions\CollectTaxEvents;
use App\Domain\Vat\Actions\ExportVatPeriod;
use App\Domain\Vat\Actions\RecordVatSubmission;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Models\VatProfile;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The steps of a return, each its own status: update → close (vat.close) → export XML →
 * submitted (date, reference, confirmation) → paid.
 */
final class VatPeriodActions
{
    public static function collect(): Action
    {
        return Action::make('collect')
            ->label(__('Update'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('create', VatProfile::class) ?? false)
            ->action(function (Action $action, ?VatPeriod $record = null): void {
                $created = 0;
                self::run($action, function () use (&$created): void {
                    $created = app(CollectTaxEvents::class)();
                });

                if ($record !== null) {
                    VatPeriodResource::forget($record);
                }

                Notification::make()->title(__(':count new entries from invoices and payments.', ['count' => $created]))->success()->send();
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label(__('Close period'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn (VatPeriod $record): bool => $record->status === VatPeriodStatus::Open && (auth()->user()?->can('close', $record) ?? false))
            ->disabled(fn (VatPeriod $record): bool => ! VatPeriodResource::figures($record)['complete'])
            ->tooltip(fn (VatPeriod $record): ?string => VatPeriodResource::figures($record)['complete'] ? null : __('Preview, not complete'))
            ->requiresConfirmation()
            ->modalHeading(__('Close VAT period'))
            ->modalDescription(fn (VatPeriod $record): string => __('Amount payable :amount. The figures are frozen and never recalculated; later changes go into a correction. The report (PDF) and the detail (CSV) are filed.', [
                'amount' => Money::format((int) VatPeriodResource::figures($record)['payable_rp']),
            ]))
            ->action(function (VatPeriod $record, Action $action): void {
                self::run($action, fn () => app(CloseVatPeriod::class)($record));
                $record->refresh();
                VatPeriodResource::forget($record);
                Notification::make()->title(__('VAT period :period closed.', ['period' => $record->label()]))->success()->send();
            });
    }

    public static function export(): Action
    {
        return Action::make('export')
            ->label(__('ESTV export (XML)'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->visible(fn (VatPeriod $record): bool => in_array($record->status, [VatPeriodStatus::Closed, VatPeriodStatus::Exported], true) && (auth()->user()?->can('close', $record) ?? false))
            ->action(function (VatPeriod $record, Action $action): ?StreamedResponse {
                $result = null;
                self::run($action, function () use ($record, &$result): void {
                    $result = app(ExportVatPeriod::class)($record);
                });

                if ($result === null) {
                    return null;
                }

                $notification = Notification::make()->title(__('eCH-0217 file created. Upload it in the ESTV portal, then mark the return as submitted.'));
                $result['validated']
                    ? $notification->success()
                    : $notification->warning()->body(__('Not validated against the eCH-0217 schema (the XSD is not configured). Check the upload in the ESTV portal.'));
                $notification->send();

                return self::stream($record->refresh()->xmlDocument);
            });
    }

    public static function download(string $name, string $label, string $relation): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (VatPeriod $record): bool => $record->{$relation} !== null)
            ->action(fn (VatPeriod $record): ?StreamedResponse => self::stream($record->{$relation}));
    }

    public static function submitted(): Action
    {
        return Action::make('submitted')
            ->label(__('Mark submitted'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (VatPeriod $record): bool => in_array($record->status, [VatPeriodStatus::Closed, VatPeriodStatus::Exported], true) && (auth()->user()?->can('close', $record) ?? false))
            ->schema([
                DatePicker::make('submitted_on')->label(__('Submitted on'))->default(now())->required()->native(false)->displayFormat('d.m.Y'),
                TextInput::make('reference')->label(__('ESTV reference'))->maxLength(100),
                FileUpload::make('confirmation')->label(__('Confirmation from the ESTV portal'))
                    ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])
                    ->storeFiles(false)
                    ->maxSize((int) config('dealer.documents.max_upload_kb')),
            ])
            ->action(function (VatPeriod $record, array $data, Action $action): void {
                $file = $data['confirmation'] ?? null;
                self::run($action, fn () => app(RecordVatSubmission::class)->submitted(
                    $record,
                    (string) $data['submitted_on'],
                    $data['reference'] ?? null,
                    $file instanceof TemporaryUploadedFile ? $file->getRealPath() : null,
                    $file instanceof TemporaryUploadedFile ? $file->getClientOriginalName() : null,
                ));
                Notification::make()->title(__('Marked as submitted.'))->success()->send();
            });
    }

    public static function paid(): Action
    {
        return Action::make('paid')
            ->label(__('Mark paid'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (VatPeriod $record): bool => $record->status === VatPeriodStatus::Submitted && (auth()->user()?->can('close', $record) ?? false))
            ->schema([
                DatePicker::make('paid_on')->label(__('Paid on'))->default(now())->required()->native(false)->displayFormat('d.m.Y'),
            ])
            ->action(function (VatPeriod $record, array $data, Action $action): void {
                self::run($action, fn () => app(RecordVatSubmission::class)->paid($record, (string) $data['paid_on']));
                Notification::make()->title(__('Marked as paid.'))->success()->send();
            });
    }

    private static function stream(?Document $document): ?StreamedResponse
    {
        $version = $document?->currentVersion;

        return $version === null ? null : Storage::disk($version->disk)->download($version->path, $version->original_name);
    }

    private static function run(Action $action, Closure $callback): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();
            $action->halt();
        }
    }
}
