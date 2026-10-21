<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Support\AuditContext;
use App\Support\EncryptedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Every copy goes through here: encrypted on a non-servable disk,
 * versioned, de-duplicated by checksum, and sent back to pending whenever
 * the file changes so a person looks at the new one.
 */
final class DocumentWallet
{
    public const MAX_VERSIONS_A_DAY = 8;

    /**
     * @param  array<string, mixed>  $attributes  number, issued_at, expires_at, notes
     */
    public function store(Model $documentable, string $bytes, string $originalName, string $mime, string $type, ?int $tripId = null, array $attributes = []): DocumentVersion
    {
        $checksum = hash('sha256', $bytes);

        return DB::transaction(function () use ($documentable, $bytes, $originalName, $mime, $type, $tripId, $attributes, $checksum): DocumentVersion {
            /** @var Document $document */
            $document = $documentable->documents()->firstOrCreate(
                ['type' => $type, 'trip_id' => $tripId],
                $attributes,
            );

            if ($attributes !== []) {
                $document->fill($attributes)->save();
            }

            $latest = $document->versions()->orderByDesc('version')->first();

            if ($latest instanceof DocumentVersion && $latest->checksum === $checksum) {
                return $latest;
            }

            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: match ($mime) {
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', default => 'bin',
            };
            $path = 'documents/'.$document->getKey().'/'.Str::random(40).'.'.$extension;

            EncryptedFile::put($this->disk(), $path, $bytes);

            $version = $document->versions()->create([
                'version' => ($latest->version ?? 0) + 1,
                'disk' => $this->disk(),
                'path' => $path,
                'original_filename' => mb_substr($originalName, 0, 255),
                'mime_type' => $mime,
                'size_bytes' => strlen($bytes),
                'checksum' => $checksum,
                'uploaded_by' => Auth::id(),
                'channel' => AuditContext::channel(),
            ]);

            $latest?->forceFill(['superseded_at' => now()])->save();

            $document->forceFill([
                'status' => Document::PENDING,
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ])->save();

            return $version;
        });
    }

    public function tooManyToday(Model $documentable, string $type): bool
    {
        return DocumentVersion::query()
            ->whereHas('document', fn ($q) => $q->where('documentable_type', $documentable->getMorphClass())->where('documentable_id', $documentable->getKey())->where('type', $type))
            ->where('created_at', '>=', now()->subDay())
            ->count() >= self::MAX_VERSIONS_A_DAY;
    }

    public function verify(Document $document, ?int $userId = null): void
    {
        $document->forceFill([
            'status' => Document::VERIFIED,
            'verified_by' => $userId ?? Auth::id(),
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function reject(Document $document, string $reason, ?int $userId = null): void
    {
        $document->forceFill([
            'status' => Document::REJECTED,
            'verified_by' => $userId ?? Auth::id(),
            'verified_at' => now(),
            'rejection_reason' => mb_substr($reason, 0, 255),
        ])->save();
    }

    public function downloadUrl(DocumentVersion $version): string
    {
        return URL::temporarySignedRoute('documents.download', now()->addMinutes((int) config('documents.download_link_minutes', 5)), ['version' => $version->getKey()]);
    }

    public function previewUrl(DocumentVersion $version): string
    {
        return URL::temporarySignedRoute('documents.preview', now()->addMinutes((int) config('documents.download_link_minutes', 5)), ['version' => $version->getKey()]);
    }

    public function exists(DocumentVersion $version): bool
    {
        return Storage::disk($version->disk)->exists($version->path);
    }

    public function disk(): string
    {
        return (string) config('documents.disk', 'documents');
    }
}
