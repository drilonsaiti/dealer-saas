<?php

namespace App\Domain\Inbox\Support;

use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Support\Stammnummer;
use App\Domain\Vehicles\Support\Vin;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds the contact (by sender address) and the vehicle file an e-mail is about: VIN,
 * Stammnummer, file number or plate in subject or text; otherwise the one open sale of that
 * contact. Unclear cases stay unassigned for a person to decide.
 */
class MessageMatcher
{
    private const CANTONS = 'AG|AI|AR|BE|BL|BS|FR|GE|GL|GR|JU|LU|NE|NW|OW|SG|SH|SO|SZ|TG|TI|UR|VD|VS|ZG|ZH';

    /**
     * @return array{party: Party|null, cycle: StockCycle|null, by: string|null}
     */
    public function match(?string $fromEmail, string $subject, string $text): array
    {
        $party = $fromEmail !== null ? Party::query()->where('email', mb_strtolower($fromEmail))->orderBy('created_at')->first() : null;
        $haystack = mb_substr($subject."\n".$text, 0, 20_000);

        foreach (['vin', 'stammnummer', 'file_number', 'plate'] as $by) {
            $cycle = match ($by) {
                'vin' => $this->vin($haystack),
                'stammnummer' => $this->stammnummer($haystack),
                'file_number' => $this->fileNumber($haystack),
                default => $this->plate($haystack),
            };

            if ($cycle !== null) {
                return ['party' => $party, 'cycle' => $cycle, 'by' => $by];
            }
        }

        if ($party !== null) {
            $sales = Sale::query()->where('buyer_party_id', $party->getKey())
                ->whereHas('stockCycle', fn ($q) => $q->whereIn('status', StockCycleStatus::openValues()))
                ->pluck('stock_cycle_id')->unique();

            if ($sales->count() === 1) {
                return ['party' => $party, 'cycle' => StockCycle::query()->find($sales->first()), 'by' => 'contact'];
            }
        }

        return ['party' => $party, 'cycle' => null, 'by' => $party !== null ? 'contact' : null];
    }

    private function vin(string $text): ?StockCycle
    {
        preg_match_all('/\b[A-HJ-NPR-Z0-9]{17}\b/i', $text, $m);
        $vins = array_values(array_filter(array_map(fn (string $v): ?string => Vin::isValid($v) && preg_match('/\d/', $v) === 1 ? Vin::normalize($v) : null, $m[0])));

        return $vins === [] ? null : $this->cycleOf(Vehicle::query()->whereIn('vin', $vins)->get());
    }

    private function stammnummer(string $text): ?StockCycle
    {
        preg_match_all('/\b\d{3}[.\s]?\d{3}[.\s]?\d{3}\b/', $text, $m);
        $numbers = array_values(array_filter(array_map(fn (string $v): ?string => Stammnummer::normalize($v), $m[0])));

        return $numbers === [] ? null : $this->cycleOf(Vehicle::query()->whereIn('stammnummer', $numbers)->get());
    }

    private function fileNumber(string $text): ?StockCycle
    {
        preg_match_all('/(?<![\w-])[A-Z0-9][A-Z0-9\-\/.]{2,18}[0-9](?![\w-])/i', $text, $m);
        $tokens = array_values(array_unique(array_filter($m[0], fn (string $t): bool => preg_match('/\d/', $t) === 1 && preg_match('/[-\/.]/', $t) === 1)));

        if ($tokens === []) {
            return null;
        }

        $cycles = StockCycle::query()->whereIn('number', array_slice($tokens, 0, 200))->get();

        return $cycles->count() === 1 ? $cycles->first() : null;
    }

    private function plate(string $text): ?StockCycle
    {
        preg_match_all('/\b('.self::CANTONS.')[\s-]?(\d{1,6})\b/', $text, $m, PREG_SET_ORDER);
        $plates = [];

        foreach ($m as [, $canton, $digits]) {
            $plates[] = "{$canton} {$digits}";
            $plates[] = "{$canton}{$digits}";
            $plates[] = "{$canton}-{$digits}";
        }

        return $plates === [] ? null : $this->cycleOf(Vehicle::query()->whereIn('plate', $plates)->get());
    }

    /**
     * The open file of the car, otherwise its latest; ambiguous (two cars) → none.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     */
    private function cycleOf(Collection $vehicles): ?StockCycle
    {
        if ($vehicles->count() !== 1) {
            return null;
        }

        $vehicle = $vehicles->first();

        return $vehicle->openStockCycle()->first()
            ?? StockCycle::query()->where('vehicle_id', $vehicle->getKey())->latest('created_at')->first();
    }
}
