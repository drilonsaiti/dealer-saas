<?php

namespace App\Domain\Inbox\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A dealer's mailbox (e.g. info@garage.ch): fetched by IMAP, replies sent by its SMTP server.
 * Passwords are stored encrypted and never shown again.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property string $imap_host
 * @property int $imap_port
 * @property string $imap_encryption
 * @property string $imap_username
 * @property string $imap_folder
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_encryption
 * @property string|null $smtp_username
 * @property array<string, string>|null $secrets
 * @property bool $is_active
 * @property int|null $uid_validity
 * @property int $last_uid
 * @property Carbon|null $last_fetched_at
 * @property string|null $last_error
 */
class Mailbox extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'name', 'email', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_folder', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'is_active'];

    protected $hidden = ['secrets'];

    /** @var list<string> */
    public array $auditExclude = ['uid_validity', 'last_uid', 'last_fetched_at', 'last_error'];

    protected $attributes = ['imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_folder' => 'INBOX', 'is_active' => true, 'last_uid' => 0];

    protected function casts(): array
    {
        return [
            'secrets' => 'encrypted:array',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'is_active' => 'boolean',
            'uid_validity' => 'integer',
            'last_uid' => 'integer',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function secret(string $key): ?string
    {
        $value = $this->secrets[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function canSend(): bool
    {
        return filled($this->smtp_host) && filled($this->smtp_port);
    }

    /**
     * @param  Builder<Mailbox>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }
}
