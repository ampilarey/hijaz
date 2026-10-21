<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramUpdate;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Router;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Telegram calls this once per message. It answers 200 whatever happens,
 * because Telegram retries anything else for hours; what went wrong is
 * written on the update row instead.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Router $router): JsonResponse
    {
        if (! Bot::isConfigured()) {
            abort(404);
        }

        if (! hash_equals(Bot::webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $update = $request->json()->all();
        $updateId = $update['update_id'] ?? null;

        if (! is_int($updateId)) {
            return response()->json(['ok' => true, 'ignored' => 'no update_id']);
        }

        try {
            $record = TelegramUpdate::query()->create(['update_id' => $updateId, 'received_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $router->handle($update, $record);
        } catch (\Throwable $e) {
            Log::warning('Telegram update failed', ['update_id' => $updateId, 'error' => $e->getMessage()]);
            $record->forceFill(['error' => mb_substr($e->getMessage(), 0, 255), 'handled_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }
}
