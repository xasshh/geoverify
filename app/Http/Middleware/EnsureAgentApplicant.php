<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Party\Models\PortalAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The applicant's side of the field portal (/agent/apply).
 *
 * An applicant is a portal account, never staff, so this asks the portal guard
 * and nothing here can reach the field client: that stays behind `field`, on
 * the staff guard, until an administrator approves the application and an
 * officer exists. `proved` additionally holds every change until the email
 * address is confirmed, because that address becomes the officer's.
 */
final class EnsureAgentApplicant
{
    public function handle(Request $request, Closure $next, ?string $need = null): Response
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Sign in first.'], 401)
                : redirect()->route('agent.sign-in');
        }

        if (! $account->canSignIn()) {
            Auth::guard('portal')->logout();

            return redirect()->route('agent.sign-in')->withErrors(['email' => 'This account is suspended.']);
        }

        if ($need === 'proved' && ! $account->isProved()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Confirm your email first.'], 403)
                : redirect()->route('agent.apply')->withErrors(['email' => 'Confirm your email address first. We sent you a link.']);
        }

        return $next($request);
    }
}
