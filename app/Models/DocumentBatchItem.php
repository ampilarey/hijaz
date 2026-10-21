<?php

namespace App\Models;

use App\Support\EncryptedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_batch_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int|null $page
 * @property array<string, mixed>|null $fields
 * @property int|null $matched_pilgrim_id
 * @property string|null $match_reason
 * @property string $status
 * @property int|null $document_id
 */
class DocumentBatchItem extends Model
{
    public const MATCHED = 'matched';

    public const UNMATCHED = 'unmatched';

    public const ASSIGNED = 'assigned';

    public const ATTACHED = 'attached';

    public const DISCARDED = 'discarded';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    /** @return BelongsTo<DocumentBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DocumentBatch::class, 'document_batch_id');
    }

    /** @return BelongsTo<Pilgrim, $this> */
    public function pilgrim(): BelongsTo
    {
        return $this->belongsTo(Pilgrim::class, 'matched_pilgrim_id');
    }

    public function contents(): ?string
    {
        return EncryptedFile::contents($this->disk, $this->path);
    }

    public function hasPilgrim(): bool
    {
        return $this->matched_pilgrim_id !== null && in_array($this->status, [self::MATCHED, self::ASSIGNED], true);
    }
}
