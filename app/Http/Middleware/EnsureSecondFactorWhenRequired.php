<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Filament's own middleware sends every user to set up a second factor.
 * Hijaz wants that only for the roles that handle money and user accounts
 * (Access::SECOND_FACTOR_ROLES); everyone else may add one but is not made
 * to. Decided per request, because the panel decides its requirement at
 * boot, before anyone is signed in.
 */
class EnsureSecondFactorWhenRequired
{
    public function handle(Request $request, Closure $next): mixed
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user === null || ! $user->mustUseSecondFactor()) {
            return $next($request);
        }

        if (MultiFactorChallenge::make()->hasEnabledProviders($user)) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
