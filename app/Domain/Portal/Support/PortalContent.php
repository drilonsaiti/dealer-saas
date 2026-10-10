<?php

namespace App\Domain\Portal\Support;

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Listings\Models\Listing;
use App\Domain\Sales\Models\Sale;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the buyer sees in the portal: only documents meant for them (contract, invoices,
 * receipts, handover protocol, warranty and leasing papers — never sensitive categories or
 * the dealer's internal files), issued invoices, warranties and the photo of the advert.
 */
final class PortalContent
{
    /** @var list<string> */
    public const CUSTOMER_CATEGORIES = ['offer', 'sales_contract', 'invoice', 'credit_note', 'receipt', 'handover_protocol', 'warranty_policy', 'leasing_contract', 'customer_upload'];

    public function __construct(public readonly Sale $sale) {}

    /**
     * @return Builder<Document>
     */
    public function documentQuery(): Builder
    {
        return Document::query()
            ->whereHas('category', fn (Builder $q) => $q->whereIn('key', self::CUSTOMER_CATEGORIES)->where('sensitive', false))
            ->whereHas('links', fn (Builder $q) => $q->where(fn (Builder $l) => $l
                ->where(fn (Builder $s) => $s->where('linkable_type', 'sale')->where('linkable_id', $this->sale->getKey()))
                ->orWhere(fn (Builder $c) => $c->where('linkable_type', 'stock_cycle')->where('linkable_id', $this->sale->stock_cycle_id))))
            ->where('documents.created_at', '>=', $this->sale->created_at->copy()->subDay()); // nothing from the dealer's purchase of the car
    }

    /**
     * @return Collection<int, Document>
     */
    public function documents(): Collection
    {
        return $this->documentQuery()->with(['currentVersion', 'category'])->orderByDesc('documents.created_at')->get();
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function invoices(): Collection
    {
        return Invoice::query()->where('sale_id', $this->sale->getKey())
            ->whereNotNull('number')->where('status', '!=', InvoiceStatus::Draft->value)
            ->orderBy('issued_on')->get();
    }

    /**
     * @return Collection<int, Warranty>
     */
    public function warranties(): Collection
    {
        return Warranty::query()->with('product')->where('sale_id', $this->sale->getKey())
            ->whereIn('status', [WarrantyStatus::Draft->value, WarrantyStatus::Active->value, WarrantyStatus::Expired->value])->get();
    }

    public function photo(): ?Document
    {
        $listing = Listing::query()->where('stock_cycle_id', $this->sale->stock_cycle_id)->first();
        $id = ($listing->photo_document_ids ?? [])[0] ?? null;

        return $id === null ? null : Document::query()->with('currentVersion')->find($id);
    }
}
