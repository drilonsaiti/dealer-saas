<?php

namespace App\Domain\Financing\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The financing flow from application to payout (concept 10.1).
 */
enum FinancingStatus: string implements HasColor, HasLabel
{
    case Applied = 'applied';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ContractReceived = 'contract_received';
    case Signed = 'signed';
    case DocumentsSent = 'documents_sent';
    case PaidOut = 'paid_out';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Applied => __('Applied'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::ContractReceived => __('Contract received'),
            self::Signed => __('Signed'),
            self::DocumentsSent => __('Documents sent'),
            self::PaidOut => __('Paid out'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Applied => 'gray',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::ContractReceived => 'info',
            self::Signed => 'primary',
            self::DocumentsSent => 'warning',
            self::PaidOut => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Rejected, self::Cancelled], true);
    }

    /**
     * @return list<self> the statuses that may follow this one
     */
    public function next(): array
    {
        return match ($this) {
            self::Applied => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::ContractReceived, self::Cancelled],
            self::ContractReceived => [self::Signed, self::Cancelled],
            self::Signed => [self::DocumentsSent, self::Cancelled],
            self::DocumentsSent => [self::Cancelled],
            self::PaidOut, self::Rejected, self::Cancelled => [],
        };
    }
}
