<?php

return [

    // The private disk. Never served by the web server; only a controller reads it.
    'disk' => env('DOCUMENTS_DISK', 'documents'),

    'max_kilobytes' => (int) env('DOCUMENTS_MAX_KB', 12288),

    'mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp'],

    // Scans are ciphertext on disk under APP_KEY. Off stops new files being
    // encrypted; every payload says which it is, so old ones still read.
    'encrypt_at_rest' => (bool) env('DOCUMENTS_ENCRYPT_AT_REST', true),

    'download_link_minutes' => (int) env('DOCUMENTS_LINK_MINUTES', 5),

    // How long a passport must still be valid for after the trip returns.
    // Settings can override this per install; this is the default.
    'passport_validity_months' => (int) env('PASSPORT_VALIDITY_MONTHS', 6),

    /*
    | Reading. `tesseract` runs a binary on the server and no image leaves
    | it; `anthropic` sends the image to Claude's API and reads better;
    | `manual` proposes nothing. Whatever reads, staff accept every field.
    */
    'reader' => env('DOCUMENTS_READER', 'tesseract'),

    'tesseract' => [
        'binary' => env('TESSERACT_BIN', 'tesseract'),
        'tessdata' => env('TESSDATA_PREFIX'),
        'languages' => env('TESSERACT_LANGS', 'eng'),
        'timeout' => 60,
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('DOCUMENTS_ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'timeout' => 45,
    ],

    // Passport-size photo spec. Saudi e-visa asks for a recent colour photo
    // on a plain white background; the owner confirms the exact numbers.
    'photo' => [
        'min_width' => (int) env('PHOTO_MIN_WIDTH', 400),
        'min_height' => (int) env('PHOTO_MIN_HEIGHT', 500),
        'aspect' => (float) env('PHOTO_ASPECT', 0.78),       // width / height, 35x45 mm
        'aspect_tolerance' => 0.12,
        'max_kilobytes' => (int) env('PHOTO_MAX_KB', 4096),
    ],

];
