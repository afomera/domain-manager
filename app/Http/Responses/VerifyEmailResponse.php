<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

/**
 * After the emailed link is clicked: on to onboarding for new accounts, otherwise back to where you were
 * headed (or your domains), with a toast either way.
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $redirect = $request->user()->isOnboarded()
            ? redirect()->intended(route('domains.index'))
            : redirect()->route('onboarding');

        return $redirect->with('toast', 'Email verified');
    }
}
