<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The in-house views, and nobody else's.
 *
 * A supervisor reaching this is refused rather than redirected, which is the
 * opposite of how `supervises` treats an officer. An officer in the console took
 * a wrong turn; a supervisor here went looking. The first deserves a way back to
 * their work, the second deserves a plain no.
 */
final class EnsureAdministers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive()) {
            abort(403, 'This account is not active.');
        }

        if (! $user->administers()) {
            abort(403, 'That is an administrator view.');
        }

        return $next($request);
    }
}
