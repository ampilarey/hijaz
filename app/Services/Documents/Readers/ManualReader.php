<?php

namespace App\Services\Documents\Readers;

/** Proposes nothing: staff type every field. The fallback whatever else is configured. */
final class ManualReader implements Reader
{
    public function name(): string
    {
        return 'manual';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function read(string $bytes, string $mime, string $type): Extraction
    {
        return Extraction::empty($this->name());
    }
}
