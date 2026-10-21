<?php

namespace App\Models;

use App\Support\EncryptedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property int $version
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $checksum
 * @property string $channel
 * @property Carbon|null $superseded_at
 */
class DocumentVersion extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['superseded_at' => 'datetime', 'size_bytes' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<DocumentExtraction, $this> */
    public function extractions(): HasMany
    {
        return $this->hasMany(DocumentExtraction::class)->orderByDesc('id');
    }

    public function contents(): ?string
    {
        return EncryptedFile::contents($this->disk, $this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
