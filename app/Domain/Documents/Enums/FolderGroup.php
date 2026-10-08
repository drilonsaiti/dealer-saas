<?php

namespace App\Domain\Documents\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The six folders of a vehicle file (the structure Aziri already uses).
 */
enum FolderGroup: string implements HasLabel
{
    case Purchase = '01_purchase';
    case VehicleDocuments = '02_vehicle';
    case CostsWorkshop = '03_costs';
    case SalePayments = '04_sale';
    case Warranty = '05_warranty';
    case Financing = '06_financing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Purchase => __('Purchase'),
            self::VehicleDocuments => __('Vehicle documents'),
            self::CostsWorkshop => __('Costs / workshop'),
            self::SalePayments => __('Sale / payments'),
            self::Warranty => __('Warranty'),
            self::Financing => __('Leasing / financing'),
        };
    }

    public function number(): string
    {
        return substr($this->value, 0, 2);
    }

    /**
     * Folder name in exports, e.g. "01_Ankauf" (in the given language).
     */
    public function folderName(?string $locale = null): string
    {
        $label = $locale === null ? $this->getLabel() : (string) __($this->labelKey(), locale: $locale);

        return $this->number().'_'.str_replace([' / ', '/', ' '], ['_', '_', '_'], $label);
    }

    private function labelKey(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::VehicleDocuments => 'Vehicle documents',
            self::CostsWorkshop => 'Costs / workshop',
            self::SalePayments => 'Sale / payments',
            self::Warranty => 'Warranty',
            self::Financing => 'Leasing / financing',
        };
    }
}
