<?php

namespace App\Domain\Inbox\Models;

use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Documents\Models\Document;
use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An e-mail received in a dealer mailbox, or a reply written here (draft → sent on click).
 * Attachments are documents linked to the message (and to the matched vehicle file).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mailbox_id
 * @property string $direction
 * @property EmailStatus $status
 * @property string|null $message_id
 * @property string|null $in_reply_to
 * @property int|null $uid
 * @property string|null $from_address
 * @property string|null $from_name
 * @property list<array{email: string, name: string|null}>|null $to
 * @property list<array{email: string, name: string|null}>|null $cc
 * @property string|null $subject
 * @property string|null $body_text
 * @property string|null $body_html
 * @property Carbon|null $sent_at
 * @property string|null $party_id
 * @property string|null $stock_cycle_id
 * @property string|null $matched_by
 * @property string|null $reply_to_message_id
 * @property list<array{name: string, reason: string}>|null $quarantined
 * @property Carbon|null $read_at
 * @property Carbon|null $handled_at
 * @property string|null $handled_by
 * @property string|null $error
 * @property Carbon $created_at
 * @property-read Mailbox $mailbox
 * @property-read Party|null $party
 * @property-read StockCycle|null $stockCycle
 * @property-read EmailMessage|null $replyTo
 */
class EmailMessage extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = ['tenant_id', 'mailbox_id', 'direction', 'status', 'message_id', 'in_reply_to', 'uid', 'from_address', 'from_name', 'to', 'cc', 'subject', 'body_text', 'body_html', 'sent_at', 'reply_to_message_id'];

    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'uid' => 'integer',
            'to' => 'array',
            'cc' => 'array',
            'quarantined' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<EmailMessage>  $query
     */
    public function scopeIncoming(Builder $query): void
    {
        $query->where($query->qualifyColumn('direction'), self::IN);
    }

    public function sender(): string
    {
        return filled($this->from_name) ? "{$this->from_name} <{$this->from_address}>" : (string) $this->from_address;
    }

    /**
     * @return Collection<int, Document>
     */
    public function attachments(): Collection
    {
        return Document::query()->linkedTo($this)->with('currentVersion')->orderBy('documents.created_at')->get();
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'reply_to_message_id');
    }
}
