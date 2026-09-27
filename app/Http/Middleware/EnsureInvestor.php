<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Investment\Models\InvestorUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Somebody signed in for an investor organisation.
 *
 * Given `verified`, also that the organisation has passed KYC. The overview and
 * the aggregates are open before that; a dossier, a data room and a commission
 * are not, and an unverified investor who reaches one is sent to the overview
 * with the reason rather than refused, because they did nothing wrong.
 */
final class EnsureInvestor
{
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $account = Auth::guard('investor')->user();

        if (! $account instanceof InvestorUser) {
            return redirect()->route('invest.sign-in');
        }

        if (! $account->canSignIn()) {
            Auth::guard('investor')->logout();

            abort(403, 'This account is not active.');
        }

        if ($level === 'verified' && ! $account->isVerified()) {
            return redirect()->route('invest.overview')->with(
                'status',
                'That opens once your organisation is verified. We will email you when it is.',
            );
        }

        return $next($request);
    }
}
