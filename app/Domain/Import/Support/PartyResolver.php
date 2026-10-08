<?php

namespace App\Domain\Import\Support;

use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;

/**
 * Finds or creates the sellers and buyers named in an import ("Reflex Automobiles Sàrl" in 75
 * rows becomes one supplier). Names are compared loosely (case, accents, punctuation).
 * Addresses are completed later, e.g. from the documents.
 */
class PartyResolver
{
    private const COMPANY_MARKERS = ['ag', 'gmbh', 'sarl', 'sàrl', 'sa', 'sagl', 'ltd', 'kg', 'co', 'automobile', 'automobiles', 'garage', 'bank', 'leasing', 'auto', 'autos', 'center', 'centre'];

    public function resolve(string $name, bool $seller, RowResult $result): Party
    {
        $key = Normalize::nameKey($name);

        $party = Party::query()->get()->first(fn (Party $party): bool => Normalize::nameKey($party->displayName()) === $key
            || ($party->company_name !== null && Normalize::nameKey($party->company_name) === $key));

        $role = $seller ? null : PartyRole::Customer;

        if ($party === null) {
            $isCompany = $this->looksLikeCompany($name);
            $words = preg_split('/\s+/', trim($name)) ?: [$name];

            $party = Party::create($isCompany
                ? ['kind' => PartyKind::Company, 'company_name' => trim($name), 'roles' => []]
                : ['kind' => PartyKind::Person, 'first_name' => count($words) > 1 ? implode(' ', array_slice($words, 0, -1)) : null, 'last_name' => (string) end($words), 'roles' => []]);

            $result->created($party);
        }

        $role ??= $party->kind === PartyKind::Company ? PartyRole::Supplier : PartyRole::PrivateSeller;
        $party->addRole($role);

        if ($party->isDirty('roles')) {
            $party->save();
        }

        return $party;
    }

    /**
     * Sold cars without a buyer name in the source still need a buyer on their sale.
     */
    public function unknownBuyer(RowResult $result): Party
    {
        $result->note(__('Buyer missing; recorded as "Unknown buyer (import)".'));

        return $this->resolve(__('Unknown buyer (import)'), seller: false, result: $result);
    }

    private function looksLikeCompany(string $name): bool
    {
        $words = preg_split('/[\s.,]+/', mb_strtolower($name)) ?: [];

        return array_intersect($words, self::COMPANY_MARKERS) !== [];
    }
}
