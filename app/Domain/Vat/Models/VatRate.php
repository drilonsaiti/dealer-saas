<?php

namespace App\Domain\Vat\Models;

use App\Support\BusinessRuleException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A legal Swiss VAT rate valid for a period (8.1 % normal since 2024, 7.7 % before).
 * The same for every dealer; changes are new rows, so old invoices keep their rate.
 *
 * @property int $id
 * @property string $code
 * @property string $rate
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 */
class VatRate extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'rate', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
        ];
    }

    /**
     * The rate in percent for a code on a date, e.g. 8.1.
     */
    public static function percentFor(string $code, DateTimeInterface $on): float
    {
        $date = $on->format('Y-m-d');
        $rate = self::query()
            ->where('code', $code)
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->value('rate');

        if ($rate === null) {
            throw new BusinessRuleException(__('No VAT rate ":code" is valid on :date.', ['code' => $code, 'date' => $on->format('d.m.Y')]));
        }

        return (float) $rate;
    }
}
