<?php

namespace App\Services\Documents\Readers;

interface Reader
{
    public function name(): string;

    public function isAvailable(): bool;

    /** @param  string  $type  One of Document::READABLE. */
    public function read(string $bytes, string $mime, string $type): Extraction;
}
