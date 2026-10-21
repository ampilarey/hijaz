<?php

namespace App\Services\Pilgrims;

use App\Services\Documents\ImagePrep;

/**
 * Is this a passport-size photo? Size, shape and a plain light background,
 * against the spec in config/documents.php. Problems are words for the
 * person uploading, not a block: a visa agent's rejection costs more than
 * a warning.
 */
final class PhotoCheck
{
    /** @return list<string> */
    public static function problems(string $bytes, string $mime): array
    {
        $spec = (array) config('documents.photo');
        $problems = [];

        if (! str_starts_with($mime, 'image/')) {
            return ['A photo must be a JPEG or PNG image, not a PDF.'];
        }

        if (strlen($bytes) > ($spec['max_kilobytes'] ?? 4096) * 1024) {
            $problems[] = 'The file is larger than '.($spec['max_kilobytes'] ?? 4096).' KB.';
        }

        try {
            [$w, $h] = ImagePrep::dimensions($bytes);
        } catch (\Throwable) {
            return ['The image could not be read.'];
        }

        if ($w < ($spec['min_width'] ?? 400) || $h < ($spec['min_height'] ?? 500)) {
            $problems[] = "The photo is {$w}×{$h}; it needs at least ".($spec['min_width'] ?? 400).'×'.($spec['min_height'] ?? 500).'.';
        }

        $aspect = $w / max(1, $h);
        $wanted = (float) ($spec['aspect'] ?? 0.78);

        if (abs($aspect - $wanted) > ($spec['aspect_tolerance'] ?? 0.12)) {
            $problems[] = $aspect > 1 ? 'The photo is landscape; it must be portrait, 35 by 45.' : 'The photo is not passport shaped (35 by 45); crop it.';
        }

        try {
            $border = ImagePrep::borderStats($bytes);

            if ($border['mean'] < 170 || $border['stddev'] > 45) {
                $problems[] = 'The background does not look plain and light.';
            }
        } catch (\Throwable) {
            // Not fatal.
        }

        return $problems;
    }
}
