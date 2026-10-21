<?php

namespace Tests\Support;

use setasign\Fpdi\Fpdi;

/** Synthetic images for tests: a plain photo, a card with text, a passport with an MRZ. */
final class Images
{
    public static function photo(int $w = 450, int $h = 580, int $bg = 250): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, $bg, $bg, $bg));
        $face = imagecolorallocate($img, 190, 140, 110);
        imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w * 0.5), (int) ($h * 0.55), $face);

        return self::jpeg($img);
    }

    /** White card with lines of text drawn in a real TrueType font, big enough for OCR. */
    public static function textCard(array $lines, int $w = 1600, int $h = 1000, int $size = 36): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $ink = imagecolorallocate($img, 10, 10, 10);
        $font = self::font();
        $y = 100;

        foreach ($lines as $line) {
            imagettftext($img, $size, 0, 80, $y, $ink, $font, $line);
            $y += (int) ($size * 1.9);
        }

        return self::png($img);
    }

    public static function font(): string
    {
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ] as $font) {
            if (is_file($font)) {
                return $font;
            }
        }

        throw new \RuntimeException('No TrueType font on this machine.');
    }

    public static function pdfWithText(array $lines): string
    {
        $pdf = new Fpdi;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);

        foreach ($lines as $line) {
            $pdf->Cell(0, 8, $line, 0, 1);
        }

        return $pdf->Output('S');
    }

    public static function multiPagePdf(array $pages): string
    {
        $pdf = new Fpdi;
        $pdf->SetFont('Helvetica', '', 12);

        foreach ($pages as $lines) {
            $pdf->AddPage();
            foreach ($lines as $line) {
                $pdf->Cell(0, 8, $line, 0, 1);
            }
        }

        return $pdf->Output('S');
    }

    private static function jpeg(\GdImage $img): string
    {
        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    private static function png(\GdImage $img): string
    {
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
