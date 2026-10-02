<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * New accounts go through onboarding (confirm email, connect Cloudflare) before reaching the app.
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->isOnboarded()) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
