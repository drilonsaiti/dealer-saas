<?php

namespace App\Domain\Settings\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\NumberSequenceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Gap-free numbering per tenant and document type.
 *
 * Pattern placeholders:
 *   {YYYY} four-digit year, {YY} two-digit year,
 *   {0000} the running number, zero-padded to the number of zeros (at least one).
 * Example: "RE-{00000}" with next_value 271 gives "RE-00271".
 *
 * @property string $id
 * @property string $tenant_id
 * @property NumberSequenceKey $key
 * @property string $pattern
 * @property int $next_value
 * @property bool $reset_yearly
 * @property int|null $current_year
 * @property int $issued_count
 */
#[UseFactory(NumberSequenceFactory::class)]
class NumberSequence extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<NumberSequenceFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'key',
        'pattern',
        'next_value',
        'reset_yearly',
        'current_year',
    ];

    protected $attributes = [
        'next_value' => 1,
        'reset_yearly' => false,
        'issued_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'key' => NumberSequenceKey::class,
            'next_value' => 'integer',
            'reset_yearly' => 'boolean',
            'current_year' => 'integer',
            'issued_count' => 'integer',
        ];
    }

    public static function isValidPattern(string $pattern): bool
    {
        return preg_match('/\{0+\}/', $pattern) === 1;
    }

    public function format(int $value, int $year): string
    {
        if (! self::isValidPattern($this->pattern)) {
            throw new InvalidArgumentException("Number pattern [{$this->pattern}] needs a {0000} placeholder.");
        }

        $result = str_replace(
            ['{YYYY}', '{YY}'],
            [(string) $year, substr((string) $year, -2)],
            $this->pattern,
        );

        return (string) preg_replace_callback(
            '/\{(0+)\}/',
            fn (array $match): string => str_pad((string) $value, strlen($match[1]), '0', STR_PAD_LEFT),
            $result,
        );
    }

    /**
     * The number the next document will get, without reserving it.
     */
    public function preview(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        $value = $this->reset_yearly && $this->current_year !== null && $this->current_year !== $year
            ? 1
            : $this->next_value;

        return $this->format($value, $year);
    }

    public function hasIssuedNumbers(): bool
    {
        return $this->issued_count > 0;
    }
}
