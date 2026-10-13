<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Domain\Party\Actions\RegisterByEmail;
use App\Domain\Party\Actions\SignInWithPassword;
use App\Domain\Staff\Actions\ManageAgentApplication;
use App\Http\Middleware\EnsurePortalAccount;
use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Requests\LoginRequest;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The field portal's door, at /agent.
 *
 * One sign-in form for two kinds of people who never share a table. Somebody
 * on the staff (an officer, a supervisor, an admin, an approved agent) goes
 * through Fortify exactly as /login did, two-factor and rate limit included.
 * Anybody else with a portal account is an applicant and lands on their
 * application. Staff are looked for first, so an approved agent who also
 * verifies businesses on Enumerate with the same address reaches their work.
 *
 * Signing up here opens a portal account and starts an application, never a
 * staff account: Fortify's registration stays off, and only an
 * administrator's approval makes an officer.
 */
final class AgentPortalController
{
    public function signInForm(Request $request): Response|RedirectResponse|Responsable
    {
        if (Auth::guard('web')->check()) {
            return app(LoginResponse::class);
        }

        if (Auth::guard('portal')->check()) {
            return redirect()->route('agent.apply');
        }

        return Inertia::render('auth/Login', [
            'status' => session('status'),
            'email' => is_string(session('email')) ? session('email') : '',
        ]);
    }

    public function signIn(Request $request, SignInWithPassword $attempt): HttpResponse|Responsable
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $staff = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->exists();

        if ($staff) {
            // Fortify's own pipeline: the throttle, two-factor, the suspended
            // account check in authenticateUsing, and LoginResponse.
            $login = LoginRequest::createFrom($request);
            $login->setContainer(app())->setRedirector(app('redirect'));
            $login->validateResolved();

            return app(AuthenticatedSessionController::class)->store($login);
        }

        $key = 'agent-sign-in:'.Str::transliterate(Str::lower($data['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many tries. Wait a minute and try again.']);
        }

        $account = $attempt($data['email'], $data['password']);

        if ($account === null) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => 'Those details do not match. Check the email and password.']);
        }

        RateLimiter::clear($key);
        Auth::guard('portal')->login($account, $request->boolean('remember'));
        $request->session()->regenerate();
        $account->forceFill(['last_signed_in_at' => now()])->save();

        return redirect()->route('agent.apply');
    }

    public function signUpForm(): Response|RedirectResponse
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('agent.apply');
        }

        return Inertia::render('agent/SignUp');
    }

    public function signUp(Request $request, RegisterByEmail $register, ManageAgentApplication $manage): RedirectResponse
    {
        $data = $request->validate([
            'person_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        // Staff already have a way in, and an address is one person's.
        if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->exists()) {
            return back()->withErrors(['email' => 'You already work with us under this address. Sign in instead.'])->onlyInput('email', 'person_name');
        }

        try {
            $result = $register($data['person_name'], $data['email'], $data['password'], null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => 'That email already has an account, perhaps from Enumerate. Sign in with it instead.'])->onlyInput('email', 'person_name');
        }

        Auth::guard('portal')->login($result['account'], remember: true);
        $request->session()->regenerate();
        $manage->start($result['account']);

        // The link in the email brings them back here, not to Enumerate.
        $request->session()->put(EnsurePortalAccount::INTENDED, route('agent.apply'));

        return redirect()->route('agent.apply')->with('status', $result['mailed']
            ? 'Welcome. We have sent a link to '.$result['account']->email.'. Open it to confirm your email, then fill in your application.'
            : 'Welcome. The confirmation email did not go. Press "Send it again" in a minute.');
    }

    /** Leaves the applicant's session. A staff session signs out through Fortify. */
    public function signOut(Request $request): RedirectResponse
    {
        Auth::guard('portal')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('agent.sign-in');
    }

    /**
     * Approved: from the applicant's session to the officer's. Signing in again
     * is what proves it is them, and the password is the one they applied with.
     */
    public function startWork(Request $request): RedirectResponse
    {
        $email = AgentApplicationController::account()->email;
        Auth::guard('portal')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('agent.sign-in')
            ->with('status', 'You are approved. Sign in with the same email and password to start work.')
            ->with('email', $email);
    }
}
