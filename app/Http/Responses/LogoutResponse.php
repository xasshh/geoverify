<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/** Signing out lands on the sign in screen, not on a page that will bounce. */
final class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->route('login');
    }
}
