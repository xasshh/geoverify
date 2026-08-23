<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console is for supervisors and admins. An officer reaching it is sent to
 * their own work rather than shown an error, because it is a wrong turn, not a
 * transgression.
 */
final class EnsureSupervises
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive()) {
            abort(403, 'This account is not active.');
        }

        if (! $user->supervises()) {
            return redirect()->route('field.index');
        }

        return $next($request);
    }
}
