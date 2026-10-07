<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reserves the next number of a sequence for the current tenant.
 *
 * The sequence row is locked (SELECT ... FOR UPDATE) for the duration of the
 * transaction, so two users issuing an invoice at the same time never get the
 * same number and no number is skipped. Call it inside the same transaction
 * that saves the document, so a rollback also gives the number back.
 */
class IssueNumber
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(NumberSequenceKey $key, ?int $year = null): string
    {
        if (! $this->context->hasTenant()) {
            throw MissingTenantContext::forCreating(NumberSequence::class);
        }

        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($key, $year): string {
            /** @var NumberSequence|null $sequence */
            $sequence = NumberSequence::query()
                ->where('key', $key->value)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new RuntimeException("No number sequence [{$key->value}] is configured for this dealer.");
            }

            if ($sequence->reset_yearly && $sequence->current_year !== $year) {
                $sequence->next_value = 1;
            }

            $value = $sequence->next_value;
            $number = $sequence->format($value, $year);

            $sequence->next_value = $value + 1;
            $sequence->current_year = $year;
            $sequence->issued_count++;
            $sequence->save();

            return $number;
        });
    }
}
