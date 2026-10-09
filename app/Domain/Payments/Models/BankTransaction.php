<?php

namespace App\Domain\Payments\Models;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\MatchStatus;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One booking from a bank statement (camt.053/054). Credits with a known QR or creditor
 * reference become payments automatically; others are proposed or wait for assignment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $bank_account_id
 * @property string $entry_key
 * @property Carbon $booked_on
 * @property Carbon|null $value_on
 * @property int $amount_rp
 * @property string $currency
 * @property string|null $reference
 * @property string|null $counterparty
 * @property string|null $remittance
 * @property array<string, mixed>|null $raw
 * @property MatchStatus $match_status
 * @property string|null $proposed_invoice_id
 * @property string|null $payment_id
 * @property string|null $source_file
 * @property-read BankAccount $bankAccount
 * @property-read Invoice|null $proposedInvoice
 * @property-read Payment|null $payment
 */
class BankTransaction extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'bank_account_id', 'entry_key', 'booked_on', 'value_on', 'amount_rp', 'currency', 'reference', 'counterparty', 'remittance', 'raw', 'source_file'];

    protected $attributes = [
        'match_status' => 'unmatched',
        'currency' => 'CHF',
    ];

    protected function casts(): array
    {
        return [
            'booked_on' => 'date:Y-m-d',
            'value_on' => 'date:Y-m-d',
            'amount_rp' => 'integer',
            'raw' => 'array',
            'match_status' => MatchStatus::class,
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function proposedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'proposed_invoice_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isCredit(): bool
    {
        return $this->amount_rp > 0;
    }
}
