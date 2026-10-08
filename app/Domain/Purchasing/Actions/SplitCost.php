<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\Cost;
use App\Support\BusinessRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One supplier invoice spread over several cars (e.g. one transport of four vehicles):
 * one cost line per vehicle file, sharing a split group. Split equally to the Rappen;
 * the first lines take the leftover Rappen so the parts always add up to the total.
 */
class SplitCost
{
    public function __construct(private readonly RecordCost $recordCost) {}

    /**
     * @param  array<string, mixed>  $data  shared attributes, with gross_rp = invoice total
     * @param  list<string>  $cycleIds
     * @return Collection<int, Cost>
     */
    public function __invoke(array $data, array $cycleIds): Collection
    {
        $cycleIds = array_values(array_unique($cycleIds));

        if (count($cycleIds) < 2) {
            throw new BusinessRuleException(__('Choose at least two vehicles to split a cost.'));
        }

        $parts = self::parts((int) $data['gross_rp'], count($cycleIds));
        $vatParts = isset($data['vat_rp']) ? self::parts((int) $data['vat_rp'], count($cycleIds)) : null;
        $group = (string) Str::uuid7();

        return DB::transaction(function () use ($data, $cycleIds, $parts, $vatParts, $group): Collection {
            return collect($cycleIds)->map(function (string $cycleId, int $i) use ($data, $parts, $vatParts, $group): Cost {
                $cost = ($this->recordCost)([
                    ...$data,
                    'stock_cycle_id' => $cycleId,
                    'gross_rp' => $parts[$i],
                    'vat_rp' => $vatParts[$i] ?? null,
                ]);

                $cost->forceFill(['split_group_id' => $group])->save();

                return $cost;
            });
        });
    }

    /**
     * @return list<int>
     */
    public static function parts(int $total, int $count): array
    {
        $base = intdiv($total, $count);
        $rest = $total - $base * $count;

        return array_map(fn (int $i): int => $base + ($i < $rest ? 1 : 0), range(0, $count - 1));
    }
}
