<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Campaign\Models\ClientUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The commissioning client's own surface.
 *
 * Third of three guards. A staff session cannot reach it and a client session
 * cannot reach the console or the admin views, because they are different
 * guards rather than different roles.
 *
 * An account whose organisation has been suspended is signed out rather than
 * left holding a live session: a lapsed contract should stop reading campaigns
 * at the next request, not at the next cookie expiry.
 */
final class EnsureClientUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::guard('client')->user();

        if (! $account instanceof ClientUser) {
            return redirect()->route('client.sign-in');
        }

        if (! $account->canSignIn()) {
            Auth::guard('client')->logout();

            abort(403, 'This account is not active.');
        }

        return $next($request);
    }
}
