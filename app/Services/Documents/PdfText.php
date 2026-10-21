<?php

namespace App\Services\Documents;

use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser;

/**
 * The text layer of a PDF, per page. E-visas from the Saudi system are
 * generated PDFs with real text, so no OCR is needed for them.
 */
final class PdfText
{
    /** @return list<string>  One string per page; empty strings for pages with no text. */
    public static function pages(string $bytes): array
    {
        try {
            $pdf = (new Parser)->parseContent($bytes);
        } catch (\Throwable) {
            return [];
        }

        $pages = [];

        foreach ($pdf->getPages() as $page) {
            try {
                $pages[] = trim($page->getText());
            } catch (\Throwable) {
                $pages[] = '';
            }
        }

        return $pages;
    }

    public static function all(string $bytes): string
    {
        return implode("\n\n", self::pages($bytes));
    }

    public static function pageCount(string $bytes): int
    {
        return count(self::pages($bytes));
    }

    /** One single-page PDF per page, or null when the file cannot be split (compressed object streams). */
    public static function split(string $bytes): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hjz');
        file_put_contents($tmp, $bytes);

        try {
            $probe = new Fpdi;
            $count = $probe->setSourceFile($tmp);
            $pages = [];

            for ($n = 1; $n <= $count; $n++) {
                $pdf = new Fpdi;
                $pdf->setSourceFile($tmp);
                $tpl = $pdf->importPage($n);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
                $pages[] = $pdf->Output('S');
            }

            return $pages;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }
}
