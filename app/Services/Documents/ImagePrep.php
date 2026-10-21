<?php

namespace App\Services\Documents;

use GdImage;
use RuntimeException;

/**
 * The little image work a reader needs, with GD because that is what the
 * host has: decode, scale, grey, crop. Never touches the stored original.
 */
final class ImagePrep
{
    public static function decode(string $bytes): GdImage
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('Not an image GD can read.');
        }

        return $image;
    }

    /** @return array{int, int} */
    public static function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw new RuntimeException('Not an image.');
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /** A grey PNG no wider than $maxWidth, which is what OCR reads best. */
    public static function forOcr(string $bytes, int $maxWidth = 2200): string
    {
        $src = self::decode($bytes);
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = $w > $maxWidth ? $maxWidth / $w : ($w < 1200 ? 1200 / $w : 1.0);
        $dst = imagescale($src, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC);

        if ($dst === false) {
            throw new RuntimeException('Could not scale the image.');
        }

        imagefilter($dst, IMG_FILTER_GRAYSCALE);
        imagefilter($dst, IMG_FILTER_CONTRAST, -15);

        return self::png($dst);
    }

    /** The bottom part of an image, where a passport's machine-readable zone sits. */
    public static function bottom(string $bytes, float $fraction = 0.3): string
    {
        $src = self::decode($bytes);
        $w = imagesx($src);
        $h = imagesy($src);
        $top = (int) floor($h * (1 - $fraction));
        $crop = imagecrop($src, ['x' => 0, 'y' => $top, 'width' => $w, 'height' => $h - $top]);

        if ($crop === false) {
            throw new RuntimeException('Could not crop the image.');
        }

        return self::png($crop);
    }

    /** Mean brightness and spread of the border pixels: a plain white background is bright and even. */
    public static function borderStats(string $bytes): array
    {
        $src = self::decode($bytes);
        $w = imagesx($src);
        $h = imagesy($src);
        $samples = [];
        $step = max(1, (int) floor(max($w, $h) / 60));

        for ($x = 0; $x < $w; $x += $step) {
            foreach ([0, (int) ($h * 0.02), $h - 1] as $y) {
                $samples[] = self::brightness($src, $x, $y);
            }
        }

        for ($y = 0; $y < $h; $y += $step) {
            foreach ([0, (int) ($w * 0.02), $w - 1] as $x) {
                $samples[] = self::brightness($src, $x, $y);
            }
        }

        $mean = array_sum($samples) / max(1, count($samples));
        $variance = array_sum(array_map(fn (float $s): float => ($s - $mean) ** 2, $samples)) / max(1, count($samples));

        return ['mean' => $mean, 'stddev' => sqrt($variance)];
    }

    private static function brightness(GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, max(0, $x), max(0, $y));

        return (0.299 * (($rgb >> 16) & 0xFF)) + (0.587 * (($rgb >> 8) & 0xFF)) + (0.114 * ($rgb & 0xFF));
    }

    private static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 3);

        return (string) ob_get_clean();
    }
}
