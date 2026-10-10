<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\AccountingExport;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Undoes the latest export (e.g. the accountant has not imported it, or the accounts were
 * wrong): its records become exportable again; the file stays in the archive, marked cancelled.
 */
class CancelAccountingExport
{
    public function __invoke(AccountingExport $export): AccountingExport
    {
        if ($export->cancelled_at !== null) {
            throw new BusinessRuleException(__('This export is already cancelled.'));
        }

        $latest = AccountingExport::query()->whereNull('cancelled_at')->latest('created_at')->latest('number')->first();

        if ($latest === null || ! $latest->is($export)) {
            throw new BusinessRuleException(__('Only the latest export can be cancelled.'));
        }

        return DB::transaction(function () use ($export): AccountingExport {
            $export->items()->delete();
            $export->forceFill(['cancelled_at' => now(), 'cancelled_by' => auth()->id()])->save();

            return $export;
        });
    }
}
