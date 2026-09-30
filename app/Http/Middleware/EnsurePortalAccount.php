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
    /** Where a signed-out visitor to the portal was going. See SignInController::home(). */
    public const INTENDED = 'portal.intended';

    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            // Remembered under the portal's own key, not Laravel's shared
            // url.intended: the staff login writes that one too, and a buyer
            // who had once opened /console would be sent there after their
            // code and bounced to the staff sign-in. Only a page someone can
            // land on is remembered, so a POST is never replayed as a GET.
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put(self::INTENDED, $request->fullUrl());
            }

            // Enumerate shares these accounts but has its own front door, so
            // somebody on their way to a verification signs in there.
            return redirect()->route($request->is('enumerate', 'enumerate/*') ? 'enumerate.sign-in' : 'portal.sign-in');
        }

        if (! $account->canSignIn()) {
            Auth::guard('portal')->logout();

            abort(403, 'This account is suspended.');
        }

        return $next($request);
    }
}
