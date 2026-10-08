<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One stored file of a document. The SHA-256 is unique per dealer (exact duplicates are
 * refused); a locked version (signed or archived) can never be replaced.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property int $version_no
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property int|null $page_count
 * @property OcrStatus $ocr_status
 * @property string|null $ocr_text
 * @property array<string, mixed>|null $data_snapshot
 * @property Carbon|null $locked_at
 * @property Carbon|null $retain_until
 * @property Carbon $created_at
 * @property-read Document $document
 */
class DocumentVersion extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $hidden = ['ocr_text', 'search_vector'];

    /** @var list<string> */
    public array $auditExclude = ['ocr_text', 'search_vector', 'ocr_status', 'page_count'];

    protected $fillable = [
        'tenant_id',
        'document_id',
        'version_no',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'sha256',
        'page_count',
        'ocr_status',
        'data_snapshot',
        'retain_until',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'size' => 'integer',
            'page_count' => 'integer',
            'ocr_status' => OcrStatus::class,
            'data_snapshot' => 'array',
            'locked_at' => 'datetime',
            'retain_until' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION) ?: 'bin');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function contents(): string
    {
        return (string) Storage::disk($this->disk)->get($this->path);
    }
}
