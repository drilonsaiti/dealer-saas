<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Support\AccountChart;
use App\Support\BusinessRuleException;

/**
 * Saves the dealer's account numbers. A number equal to the default is not stored, so a later
 * change of the default reaches the dealer too.
 */
class SaveAccountMappings
{
    /**
     * @param  array<string, string|null>  $accounts  key => account number
     */
    public function __invoke(array $accounts): void
    {
        $chart = new AccountChart;
        $defaults = collect($chart->rows())->pluck('default', 'key');

        foreach ($accounts as $key => $account) {
            if (! $defaults->has($key)) {
                continue;
            }

            $account = trim((string) $account);

            if ($account !== '' && preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $account) !== 1) {
                throw new BusinessRuleException(__('":account" is not a valid account number.', ['account' => $account]));
            }

            if ($account === '' || $account === $defaults[$key]) {
                AccountMapping::query()->where('key', $key)->delete();

                continue;
            }

            AccountMapping::query()->updateOrCreate(['key' => $key], ['account' => $account]);
        }
    }
}
