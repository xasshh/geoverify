<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The desk: drawing and importing features of the land over imagery.
 *
 * Desk digitisers and administrators. Refused, not redirected, like /admin:
 * nobody else has a reason to be here.
 */
final class EnsureDigitises
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive()) {
            abort(403, 'This account is not active.');
        }

        if (! $user->digitises()) {
            abort(403, 'That is the desk digitising view.');
        }

        return $next($request);
    }
}
