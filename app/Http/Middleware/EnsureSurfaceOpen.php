<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A surface that is built but not yet open to the public.
 *
 * Decided 2026-10-09: the business portal and the investor portal are coming
 * soon, so Enumerate stands alone. Closed, a page answers "coming soon", any
 * other request is not found, and the business dashboard (where a signed in
 * Enumerate person would otherwise land) sends them to Enumerate instead.
 * Opened by config geoverify.surfaces, so nothing is deleted to close them.
 */
final class EnsureSurfaceOpen
{
    public function handle(Request $request, Closure $next, string $surface): Response
    {
        if (self::open($surface)) {
            return $next($request);
        }

        if ($surface === 'portal' && $request->routeIs('portal.dashboard')) {
            return redirect('/enumerate');
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return Inertia::render('public/ComingSoon', ['surface' => $surface])->toResponse($request);
        }

        abort(404);
    }

    public static function open(string $surface): bool
    {
        return (bool) config("geoverify.surfaces.{$surface}", true);
    }
}
