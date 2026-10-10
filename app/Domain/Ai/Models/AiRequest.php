<?php

namespace App\Domain\Ai\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string $purpose
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $status
 * @property string|null $error
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
class AiRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'user_id', 'purpose', 'subject_type', 'subject_id', 'model', 'input_tokens', 'output_tokens', 'status', 'error', 'duration_ms'];

    protected function casts(): array
    {
        return ['input_tokens' => 'integer', 'output_tokens' => 'integer', 'duration_ms' => 'integer', 'created_at' => 'datetime'];
    }
}
