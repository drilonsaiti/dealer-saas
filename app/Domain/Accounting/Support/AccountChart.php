<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Settings\Models\BankAccount;
use InvalidArgumentException;

/**
 * Which account each booking goes to. Defaults follow the Swiss SME chart of accounts
 * (Käfer / KMU-Kontenrahmen); the dealer (or the accountant) can change every number.
 *
 * Keys: fixed ones below, plus "bank:<bank account id>" (own account per bank account) and
 * "cost:<cost category key>".
 */
class AccountChart
{
    /** @var array<string, array{0: string, 1: string}> key => [default account, English label] */
    public const DEFAULTS = [
        'cash' => ['1000', 'Cash'],
        'bank' => ['1020', 'Bank'],
        'card' => ['1020', 'Card payments'],
        'twint' => ['1020', 'TWINT'],
        'financing_payout' => ['1020', 'Leasing / credit payouts'],
        'trade_in_offset' => ['2000', 'Trade-in offset'],
        'receivables' => ['1100', 'Accounts receivable'],
        'input_vat' => ['1170', 'Input VAT'],
        'payables' => ['2000', 'Accounts payable'],
        'customer_deposits' => ['2030', 'Customer deposits'],
        'vat_due' => ['2200', 'VAT due'],
        'vehicle_sales' => ['3200', 'Vehicle sales'],
        'services' => ['3400', 'Services and other sales'],
        'vehicle_purchases' => ['4200', 'Vehicle purchases'],
    ];

    /** @var array<string, string> cost category key => default account */
    public const COST_DEFAULTS = [
        'transport' => '4200',
        'auction_fees' => '4200',
        'registration' => '4200',
    ];

    public const COST_DEFAULT = '4400';

    /** @var array<string, string>|null */
    private ?array $saved = null;

    public function account(string $key): string
    {
        $saved = $this->saved();

        if (isset($saved[$key])) {
            return $saved[$key];
        }

        // A bank account without its own number uses the general bank account.
        return str_starts_with($key, 'bank:') ? $this->account('bank') : self::default($key);
    }

    /**
     * @return array<string, string>
     */
    private function saved(): array
    {
        return $this->saved ??= AccountMapping::query()->pluck('account', 'key')->all();
    }

    public static function default(string $key): string
    {
        if (str_starts_with($key, 'cost:')) {
            return self::COST_DEFAULTS[substr($key, 5)] ?? self::COST_DEFAULT;
        }

        return self::DEFAULTS[$key][0] ?? throw new InvalidArgumentException("Unknown account key {$key}");
    }

    /**
     * Money account of a payment: cash, the bank account (own number possible), card, TWINT,
     * financing payout, or payables for a trade-in offset.
     */
    public function moneyAccount(PaymentMethod $method, ?string $bankAccountId): string
    {
        return match ($method) {
            PaymentMethod::Cash => $this->account('cash'),
            PaymentMethod::Card => $this->account('card'),
            PaymentMethod::Twint => $this->account('twint'),
            PaymentMethod::FinancingPayout => $this->account('financing_payout'),
            PaymentMethod::TradeInOffset => $this->account('trade_in_offset'),
            PaymentMethod::Bank => $this->account($bankAccountId !== null ? "bank:{$bankAccountId}" : 'bank'),
        };
    }

    /**
     * Every key with its label, default and current account (for the settings screen).
     *
     * @return list<array{key: string, label: string, default: string, account: string}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach (self::DEFAULTS as $key => [$default, $label]) {
            $rows[] = ['key' => $key, 'label' => __($label), 'default' => $default, 'account' => $this->account($key)];
        }

        foreach (BankAccount::query()->orderBy('label')->get() as $bank) {
            $key = 'bank:'.$bank->getKey();
            $rows[] = ['key' => $key, 'label' => __('Bank').': '.$bank->label, 'default' => $this->account('bank'), 'account' => $this->account($key)];
        }

        foreach (CostCategory::query()->orderBy('sort')->get() as $category) {
            $key = 'cost:'.$category->key;
            $rows[] = ['key' => $key, 'label' => __('Cost').': '.$category->name, 'default' => self::default($key), 'account' => $this->account($key)];
        }

        return $rows;
    }
}
