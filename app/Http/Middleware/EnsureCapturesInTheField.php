<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The field client is for officers. A supervisor lands on the console instead. */
final class EnsureCapturesInTheField
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive()) {
            abort(403, 'This account is not active.');
        }

        if (! $user->capturesInTheField()) {
            return redirect()->route('console.team');
        }

        return $next($request);
    }
}
