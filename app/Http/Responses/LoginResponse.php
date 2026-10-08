<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Sends each person to the screen their job starts on.
 *
 * A single configured home would send a field officer to the console and a
 * supervisor to a phone sized board, and both would have to navigate out of it
 * every morning.
 */
final class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $home = match (true) {
            $user instanceof User && $user->supervises() => route('console.team'),
            $user instanceof User && $user->role === Role::DeskDigitiser => route('desk.index'),
            default => route('field.index'),
        };

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => $home], 200);
        }

        return redirect()->intended($home);
    }
}
