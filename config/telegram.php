<?php

return [

    // From @BotFather. Lives only in the server .env. Empty switches the bot off.
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    // Without the @. Used for the t.me deep link that links a staff account.
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    // Telegram sends this back in X-Telegram-Bot-Api-Secret-Token on every
    // webhook call. Derived from the app key when not set, so no extra
    // secret has to be managed.
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),

    // Minutes a link code stays valid.
    'link_code_minutes' => 10,

    // Local time for the morning summary.
    'daily_summary_at' => env('TELEGRAM_DAILY_SUMMARY_AT', '08:00'),

];
