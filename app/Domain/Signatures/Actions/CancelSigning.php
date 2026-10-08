<?php

namespace App\Domain\Signatures\Actions;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Signatures\Enums\SignatureRequestStatus;
use App\Domain\Signatures\Models\SignatureRequest;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws an open signature request (or marks it expired); the contract is "final" again
 * and can be changed or sent out again. Signatures given so far are kept as evidence only.
 */
class CancelSigning
{
    public function __invoke(SignatureRequest $request, string $reason, bool $expired = false): SignatureRequest
    {
        if ($request->status !== SignatureRequestStatus::Pending) {
            throw new BusinessRuleException(__('This signature request is no longer open.'));
        }

        if (! $expired && trim($reason) === '') {
            throw new BusinessRuleException(__('Please give a reason.'));
        }

        return DB::transaction(function () use ($request, $reason, $expired): SignatureRequest {
            $request->forceFill([
                'status' => $expired ? SignatureRequestStatus::Expired : SignatureRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $expired ? null : trim($reason),
            ])->save();

            $request->signers()->update(['token_hash' => null]);
            $request->document->forceFill(['status' => DocumentStatus::Final])->save();

            return $request;
        });
    }
}
