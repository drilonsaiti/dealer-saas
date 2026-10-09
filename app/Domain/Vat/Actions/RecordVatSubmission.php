<?php

namespace App\Domain\Vat\Actions;

use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Support\VatDocuments;
use App\Support\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The two steps after the export, each its own status: submitted to the ESTV (date,
 * reference, the portal's confirmation) and paid.
 */
class RecordVatSubmission
{
    public function __construct(private readonly VatDocuments $documents) {}

    public function submitted(VatPeriod $period, string $date, ?string $reference, UploadedFile|string|null $confirmation = null, ?string $fileName = null): VatPeriod
    {
        // A return typed in by hand in the portal is submitted without an XML export.
        if (! in_array($period->status, [VatPeriodStatus::Closed, VatPeriodStatus::Exported], true)) {
            throw new BusinessRuleException(__('Close the VAT period first.'));
        }

        $on = Carbon::parse($date);

        if ($on->isFuture() || $on->lessThan($period->ends_on)) {
            throw new BusinessRuleException(__('The submission date must be after the end of the period and not in the future.'));
        }

        return DB::transaction(function () use ($period, $on, $reference, $confirmation, $fileName): VatPeriod {
            $document = $confirmation === null ? null : $this->documents->upload(
                $period,
                $confirmation,
                $fileName ?? ($confirmation instanceof UploadedFile ? $confirmation->getClientOriginalName() : basename($confirmation)),
                __('VAT submission confirmation :period', ['period' => $period->label()]),
            );

            $period->forceFill([
                'status' => VatPeriodStatus::Submitted,
                'submitted_on' => $on->toDateString(),
                'submission_reference' => filled($reference) ? trim($reference) : null,
                'submission_document_id' => $document?->getKey(),
            ])->save();

            return $period;
        });
    }

    public function paid(VatPeriod $period, string $date): VatPeriod
    {
        if ($period->status !== VatPeriodStatus::Submitted) {
            throw new BusinessRuleException(__('Mark the VAT period as submitted first.'));
        }

        $period->forceFill(['status' => VatPeriodStatus::Paid, 'paid_on' => Carbon::parse($date)->toDateString()])->save();

        return $period;
    }
}
