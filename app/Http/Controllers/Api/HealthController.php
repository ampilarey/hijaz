<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DeployedCommit;
use App\Support\DeploySecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Is this host up, and what is it running?
 *
 * Anonymous callers get a plain "ok". The deploy pipeline, which holds the
 * shared secret, additionally gets the commit that is serving, so a deploy
 * that only started cannot be mistaken for one that landed.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = [
            'ok' => true,
            'app' => config('app.name'),
            'env' => config('app.env'),
        ];

        if (DeploySecret::matches($request)) {
            $payload['commit'] = DeployedCommit::sha();
        }

        return response()->json($payload);
    }
}
