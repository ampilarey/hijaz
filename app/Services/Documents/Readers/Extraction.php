<?php

namespace App\Services\Documents\Readers;

/** What a reader proposed. Fields are strings keyed by the pilgrim field they map to. */
final class Extraction
{
    /**
     * @param  array<string, string|null>  $fields
     * @param  array<string, float>  $confidence
     */
    public function __construct(
        public readonly string $reader,
        public array $fields = [],
        public array $confidence = [],
        public ?string $rawText = null,
        public ?bool $mrzValid = null,
        public ?string $model = null,
        public ?string $error = null,
    ) {}

    public static function empty(string $reader, ?string $error = null): self
    {
        return new self($reader, error: $error);
    }

    public function set(string $field, ?string $value, float $confidence): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }

        $this->fields[$field] = trim($value);
        $this->confidence[$field] = max(0.0, min(1.0, $confidence));
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }
}
