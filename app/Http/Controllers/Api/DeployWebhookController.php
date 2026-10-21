<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Deploy\DeployTrigger;
use App\Support\DeploySecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Immediate auto-pull trigger for GitHub Actions.
 *
 * 404 when no secret is configured or the host is not allowed, 401 on a bad
 * secret, 202 once the pull script has been spawned.
 */
class DeployWebhookController extends Controller
{
    public function __invoke(Request $request, DeployTrigger $trigger): JsonResponse
    {
        if (DeploySecret::configured() === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->isAllowedHost($request)) {
            Log::warning('Deploy webhook rejected: host not allowed', [
                'host' => $request->getHost(),
                'app_url' => config('app.url'),
            ]);

            return response()->json(['message' => 'Not found'], 404);
        }

        if (! DeploySecret::matches($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $sha = $request->input('sha');
        $expectedSha = is_string($sha) && preg_match('/^[0-9a-f]{40}$/i', $sha) === 1
            ? strtolower($sha)
            : null;

        try {
            $result = $trigger->triggerAsync($expectedSha);
        } catch (\Throwable $e) {
            Log::error('Deploy webhook failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Deploy trigger failed', 'error' => $e->getMessage()], 500);
        }

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 503);
        }

        return response()->json([
            'message' => $result['message'],
            'sha' => $expectedSha,
            'log' => $trigger->logPath(),
        ], 202);
    }

    private function isAllowedHost(Request $request): bool
    {
        /** @var list<string> $allowed */
        $allowed = config('deploy.allowed_hosts', ['hijaz.rihla.mv']);
        $allowed = array_map(static fn (string $h): string => strtolower($h), $allowed);

        $requestHost = strtolower($request->getHost());

        if (in_array($requestHost, $allowed, true)) {
            return true;
        }

        $appUrl = (string) config('app.url', '');
        $appHost = strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?: ''));

        return $appHost !== '' && in_array($appHost, $allowed, true);
    }
}
