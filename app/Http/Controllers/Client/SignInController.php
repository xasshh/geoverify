<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A commissioning client signs in.
 *
 * Email and password, unlike the portal's phone code. The audience is different:
 * a named administrator at an agency with a work address, not a market trader
 * proving they hold a number an officer wrote down. There is no self sign up:
 * a client user is created alongside the organisation.
 */
final class SignInController
{
    public function show(): Response
    {
        return Inertia::render('client/SignIn');
    }

    public function signIn(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('client')->attempt($credentials, $request->boolean('remember'))) {
            // One message for a wrong address and a wrong password alike. Two
            // would let anybody test whether a given agency has an account here.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an account.',
            ]);
        }

        $account = Auth::guard('client')->user();

        if (! $account instanceof ClientUser || ! $account->canSignIn()) {
            Auth::guard('client')->logout();

            throw ValidationException::withMessages([
                'email' => 'That account is not active. Speak to your contact here.',
            ]);
        }

        $request->session()->regenerate();

        $account->forceFill(['last_signed_in_at' => Carbon::now(config('app.timezone'))])->save();

        VerificationEvent::record($account, 'client.signed_in', null, [
            'client_organisation_id' => $account->client_organisation_id,
        ], VerificationEvent::ACTOR_EXTERNAL);

        return to_route('client.dashboard');
    }

    public function signOut(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('client.sign-in');
    }
}
