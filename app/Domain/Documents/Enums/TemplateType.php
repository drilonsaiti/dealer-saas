<?php

namespace App\Domain\Documents\Enums;

use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Signatures\Enums\SignerRole;
use Filament\Support\Contracts\HasLabel;

/**
 * Documents the system generates from structured data. Each has a Blade view, versioned
 * clauses per dealer, a document category and (where numbered) a number range.
 */
enum TemplateType: string implements HasLabel
{
    case SalesContract = 'sales_contract';
    case PurchaseContract = 'purchase_contract';

    public function getLabel(): string
    {
        return match ($this) {
            self::SalesContract => __('Sales contract'),
            self::PurchaseContract => __('Purchase contract'),
        };
    }

    /**
     * The same label in the language of the document (customer documents follow the customer).
     */
    public function labelIn(string $locale): string
    {
        return (string) __(match ($this) {
            self::SalesContract => 'Sales contract',
            self::PurchaseContract => 'Purchase contract',
        }, locale: $locale);
    }

    public function categoryKey(): string
    {
        return $this->value;
    }

    public function numberSequence(): NumberSequenceKey
    {
        return NumberSequenceKey::Contract;
    }

    public function view(): string
    {
        return match ($this) {
            self::SalesContract => 'documents.contracts.sales',
            self::PurchaseContract => 'documents.contracts.purchase',
        };
    }

    /**
     * Which signature field ("left" / "right" in the template) a signer fills.
     */
    public function signatureSide(SignerRole $role): string
    {
        return match ($this) {
            // Seller left, buyer right; in a sale the dealer is the seller.
            self::SalesContract => $role === SignerRole::Dealer ? 'left' : 'right',
            self::PurchaseContract => $role === SignerRole::Customer ? 'left' : 'right',
        };
    }
}
