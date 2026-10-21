<?php

namespace App\Services\Finance;

use App\Services\Operations\ListDocument;

/** A report: a title, a few lines of headline figures, and one or more tables. PDF and CSV come from the same thing. */
final class ReportDocument
{
    /**
     * @param  list<array{label: string, value: string}>  $figures
     * @param  list<ListDocument>  $sections
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $figures,
        public readonly array $sections,
        public readonly array $notes = [],
        public readonly string $filename = 'report',
        public readonly bool $landscape = false,
    ) {}

    public function csv(): string
    {
        $out = "\xEF\xBB\xBF".$this->title."\n".$this->subtitle."\n\n";

        foreach ($this->figures as $f) {
            $out .= '"'.str_replace('"', '""', $f['label']).'","'.str_replace('"', '""', $f['value'])."\"\n";
        }

        foreach ($this->sections as $section) {
            $out .= "\n".$section->title."\n".substr($section->csv(), 3);
        }

        return $out;
    }
}
