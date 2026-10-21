<?php

use App\Http\Controllers\Api\DeployWebhookController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Reports the running commit to a caller holding the deploy secret, which is
// how the deploy workflow tells a deploy that landed from one that only started.
Route::get('/health', HealthController::class)->middleware('throttle:60,1');

// Immediate deploy trigger (GitHub Actions → cPanel). Disabled when
// DEPLOY_WEBHOOK_SECRET is unset; always 404 on hosts not in DEPLOY_ALLOWED_HOSTS.
Route::post('/deploy/pull', DeployWebhookController::class)->middleware('throttle:10,1');

// Telegram delivers every bot message here. 404 until a bot token is set;
// 403 without the secret Telegram was given when the webhook was registered.
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('api.telegram.webhook');
