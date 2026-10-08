<?php

namespace App\Domain\Signatures\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Parties\Models\Party;
use App\Domain\Signatures\Enums\SignerRole;
use App\Domain\Signatures\Enums\SigningMethod;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person who signs, with the evidence of how they were identified and when and where
 * they signed. The ID check (document type and number) is stored encrypted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $signature_request_id
 * @property int $position
 * @property SignerRole $role
 * @property SigningMethod $method
 * @property string|null $party_id
 * @property string|null $user_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $locale
 * @property string|null $token_hash
 * @property string|null $code_hash
 * @property string|null $code_channel
 * @property Carbon|null $code_sent_at
 * @property int $code_attempts
 * @property Carbon|null $code_verified_at
 * @property array<string, mixed>|null $identification
 * @property string $status
 * @property Carbon|null $signed_at
 * @property string|null $place
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $signature_path
 * @property string|null $signature_sha256
 * @property-read SignatureRequest $request
 * @property-read Party|null $party
 * @property-read User|null $user
 */
class Signer extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SIGNED = 'signed';

    /** @var list<string> */
    public array $auditExclude = ['token_hash', 'code_hash', 'identification'];

    protected $fillable = ['tenant_id', 'signature_request_id', 'position', 'role', 'method', 'party_id', 'user_id', 'name', 'email', 'phone', 'locale'];

    protected $hidden = ['token_hash', 'code_hash', 'identification'];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'code_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'role' => SignerRole::class,
            'method' => SigningMethod::class,
            'code_sent_at' => 'datetime',
            'code_attempts' => 'integer',
            'code_verified_at' => 'datetime',
            'identification' => 'encrypted:array',
            'signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SignatureRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    /**
     * "079 *** ** 67" — enough for the evidence page without printing the full number.
     */
    public function maskedPhone(): ?string
    {
        if ($this->phone === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $this->phone) ?? '';

        return strlen($digits) < 4 ? '***' : '*** '.substr($digits, -4);
    }
}
