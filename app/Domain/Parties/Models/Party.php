<?php

namespace App\Domain\Parties\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Enums\Salutation;
use App\Domain\Parties\Support\PhoneNumber;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Anyone the dealer deals with: customer, private seller, supplier, leasing bank, warranty
 * provider, workshop... One record per person or company, with several roles.
 * Date of birth and ID number are encrypted (nDSG).
 *
 * @property string $id
 * @property string $tenant_id
 * @property PartyKind $kind
 * @property Collection<int, PartyRole> $roles
 * @property Salutation|null $salutation
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company_name
 * @property string|null $uid
 * @property string|null $vat_number
 * @property bool|null $vat_registered
 * @property string|null $street
 * @property string|null $zip
 * @property string|null $city
 * @property string $country
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $mobile
 * @property string|null $phone_normalized
 * @property string|null $mobile_normalized
 * @property string|null $birth_date
 * @property string|null $id_doc_type
 * @property string|null $id_doc_number
 * @property string $locale
 * @property Carbon|null $consent_marketing_at
 * @property Carbon|null $privacy_ack_at
 * @property string|null $notes
 * @property string|null $legacy_ref
 */
#[UseFactory(PartyFactory::class)]
class Party extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    use HasUuids;
    use TracksAuthors;

    /** @var list<string> */
    protected $hidden = ['birth_date', 'id_doc_number'];

    protected $fillable = [
        'tenant_id',
        'kind',
        'roles',
        'salutation',
        'first_name',
        'last_name',
        'company_name',
        'uid',
        'vat_number',
        'vat_registered',
        'street',
        'zip',
        'city',
        'country',
        'email',
        'phone',
        'mobile',
        'birth_date',
        'id_doc_type',
        'id_doc_number',
        'locale',
        'consent_marketing_at',
        'privacy_ack_at',
        'notes',
        'legacy_ref',
    ];

    protected $attributes = [
        'country' => 'CH',
        'locale' => 'de',
        'roles' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PartyKind::class,
            'roles' => AsEnumCollection::of(PartyRole::class),
            'salutation' => Salutation::class,
            'vat_registered' => 'boolean',
            'birth_date' => 'encrypted',
            'id_doc_number' => 'encrypted',
            'consent_marketing_at' => 'datetime',
            'privacy_ack_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Party $party): void {
            $party->email = blank($party->email) ? null : mb_strtolower(trim($party->email));
            $party->phone_normalized = PhoneNumber::normalize($party->phone);
            $party->mobile_normalized = PhoneNumber::normalize($party->mobile);
        });
    }

    /**
     * @param  Builder<Party>  $query
     */
    public function scopeWithRole(Builder $query, PartyRole $role): void
    {
        $query->whereJsonContains('roles', $role->value);
    }

    public function hasRole(PartyRole $role): bool
    {
        return $this->roles->contains($role);
    }

    public function addRole(PartyRole $role): void
    {
        if (! $this->hasRole($role)) {
            $this->roles = $this->roles->push($role)->values();
        }
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchasesAsSeller(): HasMany
    {
        return $this->hasMany(Purchase::class, 'seller_party_id');
    }

    /**
     * Referenced by a purchase or cost, so it must stay (the database refuses the delete too).
     */
    public function isInUse(): bool
    {
        return Purchase::query()->where('seller_party_id', $this->getKey())->orWhere('payoff_party_id', $this->getKey())->exists()
            || Cost::query()->where('supplier_party_id', $this->getKey())->exists();
    }

    public function displayName(): string
    {
        $person = trim(implode(' ', array_filter([$this->first_name, $this->last_name])));

        if ($this->kind === PartyKind::Company && filled($this->company_name)) {
            return $person === '' ? (string) $this->company_name : "{$this->company_name} ({$person})";
        }

        return $person !== '' ? $person : (string) ($this->company_name ?? __('Unnamed'));
    }

    public function addressLine(): ?string
    {
        $place = trim(implode(' ', array_filter([$this->zip, $this->city])));
        $line = implode(', ', array_filter([$this->street, $place]));

        return $line === '' ? null : $line;
    }
}
