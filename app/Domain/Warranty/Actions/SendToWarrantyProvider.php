<?php

namespace App\Domain\Warranty\Actions;

use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Domain\Warranty\Providers\EmailWarrantyGateway;
use App\Domain\Warranty\Providers\WarrantyProviderGateway;
use App\Support\BusinessRuleException;

/**
 * Sends a registration or a claim to the warranty provider, by the way the product is set up.
 * Own warranties (no provider) have nothing to send.
 */
class SendToWarrantyProvider
{
    public function warranty(Warranty $warranty): string
    {
        if (in_array($warranty->status, [WarrantyStatus::Cancelled, WarrantyStatus::Expired], true)) {
            throw new BusinessRuleException(__('This warranty is no longer valid.'));
        }

        return $this->gateway($warranty->product)->submit($warranty);
    }

    public function claim(WarrantyClaim $claim): string
    {
        return $this->gateway($claim->warranty->product)->report($claim);
    }

    public static function supports(WarrantyProduct $product): bool
    {
        return $product->submission === WarrantyProduct::SUBMIT_EMAIL;
    }

    private function gateway(WarrantyProduct $product): WarrantyProviderGateway
    {
        return match ($product->submission) {
            WarrantyProduct::SUBMIT_EMAIL => app(EmailWarrantyGateway::class),
            default => throw new BusinessRuleException(__('This warranty product is not sent to a provider (own warranty or registered by hand).')),
        };
    }
}
