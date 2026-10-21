<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a reader proposed for one upload, and what staff then accepted.
 * Append-only: a second reading is a second row.
 *
 * @property int $id
 * @property int $document_version_id
 * @property string $reader
 * @property string|null $model
 * @property array<string, mixed>|null $fields
 * @property array<string, float>|null $confidence
 * @property string|null $raw_text
 * @property bool|null $mrz_valid
 * @property string $status
 * @property array<string, mixed>|null $accepted_fields
 * @property string|null $error
 * @property Carbon $created_at
 */
class DocumentExtraction extends Model
{
    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fields' => 'array', 'confidence' => 'array', 'accepted_fields' => 'array', 'mrz_valid' => 'boolean', 'reviewed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isProposed(): bool
    {
        return $this->status === self::PROPOSED;
    }

    /** Fields the reader was unsure about, worth a second look first. */
    public function lowConfidenceFields(float $below = 0.7): array
    {
        return array_keys(array_filter($this->confidence ?? [], fn (float $c): bool => $c < $below));
    }
}
