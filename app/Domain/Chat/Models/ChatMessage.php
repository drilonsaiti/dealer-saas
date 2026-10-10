<?php

namespace App\Domain\Chat\Models;

use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Documents\Models\Document;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One WhatsApp message with a customer.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_account_id
 * @property string $direction
 * @property string|null $external_id
 * @property string $phone
 * @property string|null $contact_name
 * @property string|null $body
 * @property string $status
 * @property string|null $error
 * @property string|null $party_id
 * @property string|null $stock_cycle_id
 * @property string|null $document_id
 * @property Carbon|null $read_at
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Party|null $party
 * @property-read StockCycle|null $stockCycle
 * @property-read Document|null $document
 * @property-read IntegrationAccount $account
 */
class ChatMessage extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = ['tenant_id', 'integration_account_id', 'direction', 'external_id', 'phone', 'contact_name', 'body', 'status'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** The newest message of every conversation (one row per phone number). */
    public const LATEST_PER_PHONE = 'chat_messages.created_at = (select max(c2.created_at) from chat_messages c2 where c2.phone = chat_messages.phone and c2.tenant_id = chat_messages.tenant_id)';

    public function contactLabel(): string
    {
        return $this->party?->displayName() ?? $this->contact_name ?? $this->phone;
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
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'integration_account_id');
    }
}
