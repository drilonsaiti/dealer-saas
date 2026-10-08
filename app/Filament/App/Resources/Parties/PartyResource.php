<?php

namespace App\Filament\App\Resources\Parties;

use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Filament\App\Resources\Parties\Pages\CreateParty;
use App\Filament\App\Resources\Parties\Pages\EditParty;
use App\Filament\App\Resources\Parties\Pages\ListParties;
use App\Filament\App\Resources\Parties\Schemas\PartyForm;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contacts: customers, private sellers, suppliers, leasing banks, workshops...
 *
 * @extends resource<Party>
 */
class PartyResource extends Resource
{
    protected static ?string $model = Party::class;

    protected static ?string $slug = 'contacts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('Contact');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Contacts');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(PartyForm::full());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                    ->label(__('Name'))
                    ->state(fn (Party $record): string => $record->displayName())
                    ->description(fn (Party $record): ?string => $record->addressLine())
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("coalesce(company_name, last_name) {$direction}")),
                TextColumn::make('roles')
                    ->label(__('Roles'))
                    ->badge()
                    ->formatStateUsing(fn (PartyRole $state): string => $state->getLabel()),
                TextColumn::make('email')->label(__('Email'))->placeholder('–')->copyable()->toggleable(),
                TextColumn::make('mobile')
                    ->label(__('Phone'))
                    ->state(fn (Party $record): ?string => $record->mobile ?? $record->phone)
                    ->placeholder('–')
                    ->toggleable(),
                TextColumn::make('locale')->label(__('Language'))->formatStateUsing(fn (string $state): string => strtoupper($state))->toggleable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('coalesce(company_name, last_name)'))
            ->filters([
                SelectFilter::make('roles')
                    ->label(__('Role'))
                    ->options(PartyRole::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('roles', $data['value'])
                        : $query),
                SelectFilter::make('kind')->label(__('Type'))->options(PartyKind::class),
            ]);
    }

    /**
     * @param  Builder<Party>  $query
     * @return Builder<Party>
     */
    public static function search(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $digits = preg_replace('/\D/', '', $search) ?? '';

        return $query->where(function (Builder $where) use ($term, $digits): void {
            foreach (['company_name', 'first_name', 'last_name', 'email', 'city', 'uid'] as $column) {
                $where->orWhereRaw("lower({$column}) like ?", [$term]);
            }

            $where->orWhereRaw("lower(coalesce(first_name, '') || ' ' || coalesce(last_name, '')) like ?", [$term]);

            if (strlen($digits) >= 4) {
                $where->orWhere('phone_normalized', 'like', "%{$digits}%")->orWhere('mobile_normalized', 'like', "%{$digits}%");
            }
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParties::route('/'),
            'create' => CreateParty::route('/create'),
            'edit' => EditParty::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['company_name', 'first_name', 'last_name', 'email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Party $record */
        return $record->displayName();
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Party $record */
        return array_filter([
            __('Address') => $record->addressLine(),
            __('Roles') => $record->roles->map(fn (PartyRole $role): string => $role->getLabel())->implode(', '),
        ]);
    }

    /**
     * Options for a party select, optionally limited to some roles, searchable by name.
     *
     * @param  list<PartyRole>  $roles
     * @return array<string, string>
     */
    public static function selectOptions(string $search = '', array $roles = [], int $limit = 50): array
    {
        return Party::query()
            ->when($roles !== [], fn (Builder $query) => $query->where(function (Builder $where) use ($roles): void {
                foreach ($roles as $role) {
                    $where->orWhereJsonContains('roles', $role->value);
                }
            }))
            ->when($search !== '', fn (Builder $query) => self::search($query, $search))
            ->orderByRaw('coalesce(company_name, last_name)')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Party $party): array => [$party->id => trim($party->displayName().($party->city ? ", {$party->city}" : ''))])
            ->all();
    }
}
