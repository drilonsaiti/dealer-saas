<?php

namespace App\Filament\Support;

use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Filament\App\Resources\Parties\PartyResource;
use App\Filament\App\Resources\Parties\Schemas\PartyForm;
use Filament\Forms\Components\Select;

/**
 * Pick a contact (searchable by name, email, phone) or create one on the spot.
 */
final class PartySelect
{
    /**
     * @param  list<PartyRole>  $roles  roles offered first in the search; a new contact gets the first one
     */
    public static function make(string $name, array $roles = []): Select
    {
        return Select::make($name)
            ->searchable()
            ->preload()
            ->options(fn (): array => PartyResource::selectOptions(roles: $roles, limit: 30))
            ->getSearchResultsUsing(fn (string $search): array => PartyResource::selectOptions($search))
            ->getOptionLabelUsing(fn (?string $value): ?string => $value === null ? null : Party::query()->find($value)?->displayName())
            ->createOptionForm(PartyForm::quick($roles[0] ?? PartyRole::Customer))
            ->createOptionUsing(fn (array $data): string => Party::create($data)->getKey())
            ->createOptionModalHeading(__('New contact'));
    }
}
