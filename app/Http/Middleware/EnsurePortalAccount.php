<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Party\Models\PortalAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal is for parties, and only for parties.
 *
 * A staff session cannot reach it and a portal session cannot reach the
 * console, because they are different guards rather than different roles. An
 * officer who lands here is sent to their own work, the same way the console
 * sends them, rather than being told off.
 */
final class EnsurePortalAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            // guest() rather than route(): it remembers the page, so signing in
            // returns somebody to the checkout they were sent from.
            return redirect()->guest(route('portal.sign-in'));
        }

        if (! $account->canSignIn()) {
            Auth::guard('portal')->logout();

            abort(403, 'This account is suspended.');
        }

        return $next($request);
    }
}
