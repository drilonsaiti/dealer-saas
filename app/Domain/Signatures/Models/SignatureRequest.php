<?php

namespace App\Domain\Signatures\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Signatures\Enums\SignatureProvider;
use App\Domain\Signatures\Enums\SignatureRequestStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Signing of one finalised document version. Signers sign in their order; when the last one
 * has signed, the signed version (signatures, evidence page, seal) is filed and locked.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $document_version_id
 * @property SignatureProvider $provider
 * @property SignatureRequestStatus $status
 * @property Carbon|null $expires_at
 * @property string|null $signed_version_id
 * @property array<string, mixed>|null $seal
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property-read Document $document
 * @property-read DocumentVersion $version
 * @property-read Collection<int, Signer> $signers
 */
class SignatureRequest extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'document_id', 'document_version_id', 'provider', 'expires_at'];

    protected $attributes = [
        'provider' => 'own',
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SignatureProvider::class,
            'status' => SignatureRequestStatus::class,
            'expires_at' => 'datetime',
            'seal' => 'array',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /**
     * @return HasMany<Signer, $this>
     */
    public function signers(): HasMany
    {
        return $this->hasMany(Signer::class)->orderBy('position');
    }

    /**
     * @param  Builder<SignatureRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', SignatureRequestStatus::Pending->value);
    }

    public function isOpen(): bool
    {
        return $this->status === SignatureRequestStatus::Pending && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The signer whose turn it is (signers sign in their order).
     */
    public function nextSigner(): ?Signer
    {
        return $this->signers->first(fn (Signer $signer): bool => ! $signer->hasSigned());
    }
}
