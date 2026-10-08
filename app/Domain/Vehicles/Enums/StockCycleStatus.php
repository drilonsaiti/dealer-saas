<?php

namespace App\Domain\Vehicles\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of one purchase-to-sale pass of a vehicle (spec 3.1).
 *
 * Main flow: In Prüfung → Eingekauft → Transport → Eingetroffen → In Aufbereitung →
 * Nicht inserierbereit / Verkaufsbereit → Inseriert → Reserviert → Verkauft → Ausgeliefert → Archiviert,
 * plus Storniert for a purchase that did not happen. Status only changes through
 * TransitionStockCycle, which checks the allowed transitions below and the guards.
 */
enum StockCycleStatus: string implements HasColor, HasLabel
{
    case InReview = 'in_review';
    case Purchased = 'purchased';
    case InTransit = 'in_transit';
    case Arrived = 'arrived';
    case InPreparation = 'in_preparation';
    case NotReady = 'not_ready';
    case ReadyForSale = 'ready_for_sale';
    case Listed = 'listed';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Delivered = 'delivered';
    case Archived = 'archived';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::InReview => __('In review'),
            self::Purchased => __('Purchased'),
            self::InTransit => __('In transit'),
            self::Arrived => __('Arrived'),
            self::InPreparation => __('In preparation'),
            self::NotReady => __('Not ready for listing'),
            self::ReadyForSale => __('Ready for sale'),
            self::Listed => __('Listed'),
            self::Reserved => __('Reserved'),
            self::Sold => __('Sold'),
            self::Delivered => __('Delivered'),
            self::Archived => __('Archived'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InReview, self::Archived => 'gray',
            self::Purchased, self::InTransit, self::Arrived => 'info',
            self::InPreparation, self::NotReady => 'warning',
            self::ReadyForSale, self::Listed => 'success',
            self::Reserved, self::Sold => 'primary',
            self::Delivered => 'gray',
            self::Cancelled => 'danger',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::InReview => [self::Purchased, self::Cancelled],
            self::Purchased => [self::InTransit, self::Arrived, self::InPreparation, self::NotReady, self::ReadyForSale, self::Cancelled],
            self::InTransit => [self::Arrived, self::Cancelled],
            self::Arrived => [self::InPreparation, self::NotReady, self::ReadyForSale],
            self::InPreparation => [self::NotReady, self::ReadyForSale],
            self::NotReady => [self::InPreparation, self::ReadyForSale],
            self::ReadyForSale => [self::Listed, self::Reserved, self::Sold, self::InPreparation, self::NotReady],
            self::Listed => [self::Reserved, self::Sold, self::ReadyForSale],
            self::Reserved => [self::Sold, self::ReadyForSale],
            self::Sold => [self::Delivered, self::ReadyForSale],
            self::Delivered => [self::Archived],
            self::Archived, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Going back in the flow (cancelled reservation or sale, car back to the workshop,
     * listing withdrawn) always needs a reason in the status history.
     */
    public function isBackStepTo(self $to): bool
    {
        return $to !== self::Cancelled && $to->position() < $this->position();
    }

    /**
     * Statuses reached only through their own business action (recording a purchase,
     * reserving, contracting a sale), never through a plain status button.
     */
    public function isSetByAction(): bool
    {
        return in_array($this, [self::Purchased, self::Reserved, self::Sold], true);
    }

    /**
     * Still in the dealership's hands: counts as stock and blocks a second open cycle.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Archived, self::Cancelled], true);
    }

    /**
     * Bought and not yet sold: counts toward stock and stock value.
     */
    public function isInStock(): bool
    {
        return in_array($this, [
            self::Purchased, self::InTransit, self::Arrived, self::InPreparation,
            self::NotReady, self::ReadyForSale, self::Listed, self::Reserved,
        ], true);
    }

    /**
     * Archived records are read-only (except notes); cancelled ones too.
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::Archived, self::Cancelled], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isOpen()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function inStockValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isInStock()),
        ));
    }

    private function position(): int
    {
        return match ($this) {
            self::InReview => 0,
            self::Purchased => 1,
            self::InTransit => 2,
            self::Arrived => 3,
            self::InPreparation, self::NotReady => 4,
            self::ReadyForSale => 5,
            self::Listed => 6,
            self::Reserved => 7,
            self::Sold => 8,
            self::Delivered => 9,
            self::Archived => 10,
            self::Cancelled => 11,
        };
    }
}
