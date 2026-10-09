<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\InvoiceLineKind;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vat\Models\VatCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One invoice line; prices are gross (incl. VAT), the VAT contained is computed with the
 * rate valid on the invoice date and stored with the line.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property int $position
 * @property InvoiceLineKind $kind
 * @property string $description
 * @property string $qty
 * @property int $unit_price_rp
 * @property string|null $vat_code_id
 * @property string $vat_rate
 * @property int $net_rp
 * @property int $vat_rp
 * @property int $total_rp
 * @property string|null $source_invoice_id
 * @property-read VatCode|null $vatCode
 */
class InvoiceLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'invoice_id', 'position', 'kind', 'description', 'qty', 'unit_price_rp', 'vat_code_id', 'vat_rate', 'net_rp', 'vat_rp', 'total_rp', 'source_invoice_id'];

    protected function casts(): array
    {
        return [
            'kind' => InvoiceLineKind::class,
            'position' => 'integer',
            'unit_price_rp' => 'integer',
            'net_rp' => 'integer',
            'vat_rp' => 'integer',
            'total_rp' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<VatCode, $this>
     */
    public function vatCode(): BelongsTo
    {
        return $this->belongsTo(VatCode::class);
    }
}
