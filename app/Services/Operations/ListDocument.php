<?php

namespace App\Services\Operations;

/** A printable list: a title, columns, rows. Rendered as PDF or CSV without knowing which. */
final class ListDocument
{
    /**
     * @param  array<string, string>  $columns  key => heading
     * @param  list<array<string, string|int|null>>  $rows
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $columns,
        public readonly array $rows,
        public readonly bool $landscape = false,
        public readonly array $notes = [],
        public readonly string $filename = 'list',
    ) {}

    public function csv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_values($this->columns));

        foreach ($this->rows as $row) {
            fputcsv($out, array_map(fn (string $key) => (string) ($row[$key] ?? ''), array_keys($this->columns)));
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF".$csv;
    }
}
