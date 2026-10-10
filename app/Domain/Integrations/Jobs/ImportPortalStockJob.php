<?php

namespace App\Domain\Integrations\Jobs;

use App\Domain\Integrations\Actions\ImportPortalStock;
use App\Domain\Integrations\Models\IntegrationAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The portal import can take minutes (photos); it runs in the queue, the result is in the log.
 */
class ImportPortalStockJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $accountId) {}

    public function uniqueId(): string
    {
        return $this->accountId;
    }

    public function handle(ImportPortalStock $import): void
    {
        $account = IntegrationAccount::query()->find($this->accountId);

        if ($account !== null) {
            $import($account);
        }
    }
}
