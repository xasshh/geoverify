<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Party\Actions\ConfirmPortalEmail;
use App\Domain\Party\Actions\ManagePortalCredentials;
use App\Domain\Party\Actions\RegisterByEmail;
use App\Domain\Party\Actions\SendPortalEmailVerification;
use App\Domain\Party\Enums\PartyKind;
use App\Domain\Party\Models\PortalAccount;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePortalAccount;
use App\Http\Middleware\EnsureSurfaceOpen;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The portal's way in by email: register with a password, prove the address
 * by a signed link, and reset the password by another.
 *
 * Every portal account (business owners, buyers and Enumerate requesters)
 * comes through here since SMS was switched off on 2026-10-09. Nothing behind
 * the portal door opens until the email is proved: EnsurePortalAccount sends
 * an unproved account to the notice page below.
 */
final class EmailAccountController extends Controller
{
    public function registerForm(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('portal.dashboard');
        }

        $portalOpen = EnsureSurfaceOpen::open('portal');

        // Somebody sent here from Enumerate goes back there once verified, as
        // does everybody while the business portal is closed.
        if ($request->query('next') === 'enumerate' || ! $portalOpen) {
            $request->session()->put(EnsurePortalAccount::INTENDED, url('/enumerate'));
        }

        return Inertia::render('portal/Register', [
            'audience' => $request->query('as') === 'buyer' || ! $portalOpen ? 'buyer' : 'business',
            'byEmail' => true,
            'portalOpen' => $portalOpen,
            'email' => is_string($request->query('email')) ? mb_substr($request->query('email'), 0, 180) : '',
        ]);
    }

    public function register(Request $request, RegisterByEmail $register): RedirectResponse
    {
        // No business is registered while the business portal is closed.
        $business = $request->input('audience') !== 'buyer' && EnsureSurfaceOpen::open('portal');

        $validated = $request->validate([
            'person_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
            'display_name' => [$business ? 'required' : 'nullable', 'string', 'max:180'],
            'kind' => [$business ? 'required' : 'nullable', 'string', 'in:individual,company'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $register(
                $validated['person_name'],
                $validated['email'],
                $validated['password'],
                $business ? [
                    'display_name' => (string) $validated['display_name'],
                    'kind' => PartyKind::from((string) $validated['kind']),
                    'legal_name' => $validated['legal_name'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                ] : null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->onlyInput('email', 'person_name', 'display_name', 'kind', 'legal_name', 'phone');
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['phone' => $e->getMessage()])->onlyInput('email', 'person_name', 'display_name', 'kind', 'legal_name', 'phone');
        }

        Auth::guard('portal')->login($result['account'], remember: true);
        $request->session()->regenerate();

        return redirect()->route('portal.email.notice')->with('status', $result['mailed']
            ? 'Account created. We have sent a link to '.$result['account']->email.'.'
            : 'Account created, but the email did not go. Press "Send it again" in a minute.');
    }

    /** "Check your inbox", for a signed in account that has not proved its email. */
    public function notice(Request $request): Response|RedirectResponse
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            return redirect()->route('portal.sign-in');
        }

        if ($account->isProved()) {
            return redirect()->route('portal.dashboard');
        }

        return Inertia::render('portal/VerifyEmail', ['email' => $account->email]);
    }

    public function resend(Request $request, SendPortalEmailVerification $send): RedirectResponse
    {
        $account = Auth::guard('portal')->user();

        if (! $account instanceof PortalAccount) {
            return redirect()->route('portal.sign-in');
        }

        try {
            $send($account);
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['email' => 'The email could not be sent just now. Try again in a few minutes.']);
        }

        return back()->with('status', 'Sent. Check your inbox and your spam folder.');
    }

    /**
     * The link in the email. Works on any device: the signature proves the
     * address, so nobody needs to be signed in to follow it.
     */
    public function verify(Request $request, PortalAccount $account, string $hash, ConfirmPortalEmail $confirm): RedirectResponse
    {
        if (! $request->hasValidSignature() || ! $confirm($account, $hash)) {
            return redirect()->route('portal.sign-in')->withErrors([
                'identifier' => 'That link has expired or was replaced by a newer one. Sign in and ask for a new link.',
            ]);
        }

        $current = Auth::guard('portal')->user();

        if ($current instanceof PortalAccount && $current->id === $account->id) {
            // The session's copy of the account learns it is verified now.
            Auth::guard('portal')->setUser($account);
            $intended = $request->session()->pull(EnsurePortalAccount::INTENDED);

            return redirect()->to(is_string($intended) && $this->ours($intended) ? $intended : route('portal.dashboard'))
                ->with('status', 'Your email is verified. Welcome.');
        }

        return redirect()->route('portal.sign-in')->with('status', 'Your email is verified. Sign in to continue.');
    }

    /**
     * Forgot password. The answer is the same whether or not the address has
     * an account, so the form cannot be used to find out who is registered.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:180']]);

        try {
            $this->broker()->sendResetLink(['email' => mb_strtolower(trim($validated['email']))]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['email' => 'The email could not be sent just now. Try again in a few minutes.']);
        }

        return back()->with('status', 'If that email has an account, a link to choose a new password is on its way.');
    }

    public function resetForm(Request $request): Response
    {
        return Inertia::render('portal/ResetPassword', [
            'token' => (string) $request->query('token'),
            'email' => (string) $request->query('email'),
        ]);
    }

    /** Following the reset link proves the email too, which is how an invited person is verified. */
    public function reset(Request $request, ManagePortalCredentials $credentials): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $signedIn = null;

        $status = $this->broker()->reset(
            [
                'email' => mb_strtolower(trim($validated['email'])),
                'password' => $validated['password'],
                'password_confirmation' => $validated['password'],
                'token' => $validated['token'],
            ],
            function (PortalAccount $account, string $password) use ($credentials, &$signedIn): void {
                $credentials->setPassword($account, $password, 'email-link');

                if ($account->email_verified_at === null) {
                    $account->forceFill(['email_verified_at' => now()])->save();
                }

                $signedIn = $account;
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET || ! $signedIn instanceof PortalAccount) {
            return back()->withErrors(['password' => 'That link has expired or was already used. Ask for a new one from "Forgot password".']);
        }

        if (! $signedIn->canSignIn()) {
            return redirect()->route('portal.sign-in')->withErrors(['identifier' => 'This account is suspended.']);
        }

        Auth::guard('portal')->login($signedIn);
        $request->session()->regenerate();

        return redirect()->route('portal.dashboard')->with('status', 'Your password is set.');
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker('portal_accounts');

        return $broker;
    }

    private function ours(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_starts_with($path, '/portal') || $path === '/enumerate' || str_starts_with($path, '/enumerate/');
    }
}
