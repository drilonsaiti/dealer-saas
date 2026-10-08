<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\Commitment;

/**
 * Marks a promise to the customer as kept (or reopens it). Who and when are recorded.
 */
class CompleteCommitment
{
    public function __invoke(Commitment $commitment, bool $done = true): Commitment
    {
        $commitment->forceFill([
            'done_at' => $done ? now() : null,
            'done_by' => $done ? auth()->id() : null,
        ])->save();

        return $commitment;
    }
}
