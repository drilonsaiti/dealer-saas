<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Rechnung or Gutschrift. Drafts can be edited; issuing assigns the number (gap-free), the
 * QR reference and the PDF, after which nothing changes: corrections are credit notes.
 * Amounts are gross (incl. VAT) in Rappen; vat_rp is the VAT contained.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $number
 * @property InvoiceType $type
 * @property InvoiceStatus $status
 * @property string|null $sale_id
 * @property string|null $stock_cycle_id
 * @property string $recipient_party_id
 * @property array<string, mixed>|null $recipient_snapshot
 * @property Carbon|null $issued_on
 * @property Carbon|null $due_on
 * @property Carbon|null $service_on
 * @property string $locale
 * @property int $net_rp
 * @property int $vat_rp
 * @property int $total_rp
 * @property int $paid_rp
 * @property int $credited_rp
 * @property string|null $bank_account_id
 * @property string|null $qr_iban
 * @property string|null $reference_type
 * @property string|null $qr_reference
 * @property string|null $credits_invoice_id
 * @property string|null $document_id
 * @property string|null $notes
 * @property Carbon|null $sent_at
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Party $recipient
 * @property-read Sale|null $sale
 * @property-read StockCycle|null $stockCycle
 * @property-read Invoice|null $credits
 * @property-read Document|null $document
 * @property-read BankAccount|null $bankAccount
 */
class Invoice extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    /** @var list<string> */
    public array $auditExclude = ['paid_rp', 'credited_rp'];

    protected $fillable = ['tenant_id', 'type', 'sale_id', 'stock_cycle_id', 'recipient_party_id', 'issued_on', 'due_on', 'service_on', 'locale', 'bank_account_id', 'credits_invoice_id', 'notes'];

    protected $attributes = [
        'status' => 'draft',
        'net_rp' => 0,
        'vat_rp' => 0,
        'total_rp' => 0,
        'paid_rp' => 0,
        'credited_rp' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'recipient_snapshot' => 'array',
            'issued_on' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'service_on' => 'date:Y-m-d',
            'net_rp' => 'integer',
            'vat_rp' => 'integer',
            'total_rp' => 'integer',
            'paid_rp' => 'integer',
            'credited_rp' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'recipient_party_id');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function credits(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credits_invoice_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credits_invoice_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return MorphMany<PaymentAllocation, $this>
     */
    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('type', '!=', InvoiceType::CreditNote->value);
    }

    public function openRp(): int
    {
        return max(0, $this->total_rp - $this->paid_rp - $this->credited_rp);
    }

    public function isOverdue(): bool
    {
        return $this->openRp() > 0 && $this->due_on !== null && $this->due_on->isPast() && in_array($this->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true);
    }
}
