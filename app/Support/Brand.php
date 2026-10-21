<?php

namespace App\Support;

/**
 * The palette as literal hex, for the places Tailwind classes cannot reach:
 * the Filament panel colours, generated avatars, PDFs and emails.
 *
 * Keep in step with resources/css/app.css and docs/BRAND.md.
 */
final class Brand
{
    /** The star. Large shapes and icons; too light for small text. */
    public const GREEN = '#01A85A';

    /** Buttons with white text, links, small green text. */
    public const GREEN_DARK = '#017A42';

    /** The wordmark. Large headings only. */
    public const RED = '#EC3237';

    public const RED_DARK = '#C81E24';

    /** The Kaaba. Body text. */
    public const INK = '#353334';

    public const MUTED = '#5E5C5D';

    /** The logo's original background. */
    public const SKY = '#C7D9F1';

    public const SKY_LIGHT = '#F1F6FC';
}
