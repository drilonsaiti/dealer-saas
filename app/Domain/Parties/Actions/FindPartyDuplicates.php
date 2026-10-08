<?php

namespace App\Domain\Parties\Actions;

use App\Domain\Parties\Models\Party;
use App\Domain\Parties\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Collection;

/**
 * Possible duplicates of a person or company about to be entered: same email, same phone
 * (any notation), same UID, or a very similar name. Shown as a warning, never blocking:
 * two people can share a name, and the user decides.
 */
class FindPartyDuplicates
{
    public const NAME_SIMILARITY = 0.6;

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Party>
     */
    public function __invoke(array $attributes, ?string $ignoreId = null, int $limit = 5): Collection
    {
        $email = filled($attributes['email'] ?? null) ? mb_strtolower(trim((string) $attributes['email'])) : null;
        $phones = array_values(array_filter([
            PhoneNumber::normalize($attributes['phone'] ?? null),
            PhoneNumber::normalize($attributes['mobile'] ?? null),
        ]));
        $uid = filled($attributes['uid'] ?? null) ? trim((string) $attributes['uid']) : null;
        $name = mb_strtolower(trim(implode(' ', array_filter([
            $attributes['company_name'] ?? null,
            $attributes['first_name'] ?? null,
            $attributes['last_name'] ?? null,
        ]))));

        if ($email === null && $phones === [] && $uid === null && mb_strlen($name) < 4) {
            return new Collection;
        }

        return Party::query()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($email, $phones, $uid, $name): void {
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }

                if ($phones !== []) {
                    $query->orWhereIn('phone_normalized', $phones)->orWhereIn('mobile_normalized', $phones);
                }

                if ($uid !== null) {
                    $query->orWhere('uid', $uid);
                }

                if (mb_strlen($name) >= 4) {
                    $query->orWhereRaw(
                        "similarity(lower(coalesce(company_name, '') || ' ' || coalesce(first_name, '') || ' ' || coalesce(last_name, '')), ?) >= ?",
                        [$name, self::NAME_SIMILARITY],
                    );
                }
            })
            ->limit($limit)
            ->get();
    }
}
