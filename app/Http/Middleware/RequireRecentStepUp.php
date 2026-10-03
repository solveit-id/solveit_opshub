<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $minimumTimestamp = now()->subMinutes(config('opshub.step_up_ttl_minutes'))->timestamp;

        if (! is_int($confirmedAt) || $confirmedAt < $minimumTimestamp) {
            abort(403, 'Recent step-up authentication is required.');
        }

        return $next($request);
    }
}
