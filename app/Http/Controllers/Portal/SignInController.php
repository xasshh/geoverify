<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Party\Actions\ManagePortalCredentials;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Actions\RegisterParty;
use App\Domain\Party\Actions\RequestSignInCode;
use App\Domain\Party\Actions\SignInWithPassword;
use App\Domain\Party\Actions\VerifySignInCode;
use App\Domain\Party\Enums\PartyKind;
use App\Domain\Party\Models\PortalAccount;
use App\Http\Middleware\EnsurePortalAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Getting in, and getting an account in the first place.
 *
 * One path serves both. A person proves the number is theirs, and only then is
 * it decided whether they already exist. Asking for a name before the phone is
 * proved would mean storing details against numbers nobody controls.
 */
final class SignInController
{
    /** Where a proved-but-unregistered phone waits while we ask who they are. */
    private const PENDING_PHONE = 'portal.pending_phone';

    /** A phone proved for a password reset, and nothing else. */
    private const RESET_PHONE = 'portal.reset_phone';

    /** Which door somebody came through: buying, or running a business. */
    private const AUDIENCE = 'portal.audience';

    public function show(): Response|RedirectResponse
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('portal.dashboard');
        }

        return Inertia::render('portal/SignIn');
    }

    public function requestCode(Request $request, RequestSignInCode $codes, NormalisePhone $phones): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'intent' => ['nullable', 'in:sign-in,reset'],
            'audience' => ['nullable', 'in:buyer,business'],
        ]);

        try {
            $sent = $codes($validated['phone'], $request->ip());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['phone' => $e->getMessage()]);
        }

        $request->session()->put(self::AUDIENCE, $validated['audience'] ?? 'business');

        return redirect()
            ->route('portal.verify')
            ->with('portal.phone', $sent['phone'])
            ->with('portal.masked', $sent['masked'])
            ->with('portal.intent', $validated['intent'] ?? 'sign-in');
    }

    public function verifyForm(Request $request): Response|RedirectResponse
    {
        $phone = $request->session()->get('portal.phone');

        if (! is_string($phone)) {
            return redirect()->route('portal.sign-in');
        }

        // Kept for the life of the attempt, so a failed code does not send the
        // person back to type their number again.
        $request->session()->keep(['portal.phone', 'portal.masked', 'portal.intent']);

        return Inertia::render('portal/VerifyCode', [
            'masked' => $request->session()->get('portal.masked'),
            'intent' => $request->session()->get('portal.intent', 'sign-in'),
            'resendAfterSeconds' => 60,
        ]);
    }

    /**
     * Another code to the same number, from the code screen. The number comes
     * from the session rather than the request, so this cannot be pointed at
     * somebody else's phone, and RequestSignInCode's hourly limits still hold.
     */
    public function resend(Request $request, RequestSignInCode $codes): RedirectResponse
    {
        $phone = $request->session()->get('portal.phone');

        if (! is_string($phone)) {
            return redirect()->route('portal.sign-in');
        }

        $request->session()->keep(['portal.phone', 'portal.masked', 'portal.intent']);

        try {
            $codes($phone, $request->ip());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', 'A new code is on its way. The old one no longer works.');
    }

    public function verify(Request $request, VerifySignInCode $verify): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $phone = $request->session()->get('portal.phone');

        if (! is_string($phone)) {
            return redirect()->route('portal.sign-in');
        }

        try {
            $result = $verify($phone, $validated['code']);
        } catch (RuntimeException $e) {
            $request->session()->keep(['portal.phone', 'portal.masked', 'portal.intent']);

            return back()->withErrors(['code' => $e->getMessage()]);
        }

        // A reset proves the phone and then asks for the new password. It
        // never signs anybody in on its own, and it needs an account to reset.
        if ($request->session()->get('portal.intent') === 'reset') {
            if (! $result['account'] instanceof PortalAccount) {
                return redirect()->route('portal.sign-in')->withErrors([
                    'phone' => 'There is no account for that number yet. Sign in with a code to start one.',
                ]);
            }

            $request->session()->put(self::RESET_PHONE, $result['phone']);

            return redirect()->route('portal.reset-password');
        }

        if ($result['account'] instanceof PortalAccount) {
            Auth::guard('portal')->login($result['account'], remember: true);
            $request->session()->regenerate();

            // Back to where they were going: a buyer sent here from checkout
            // should land on their cart, not on a dashboard about nothing.
            return $this->home($request);
        }

        // Proved, but nobody here yet. Carry the number forward and ask who
        // they are, without asking them to prove it again.
        $request->session()->put(self::PENDING_PHONE, $result['phone']);

        return redirect()->route('portal.register');
    }

    public function registerForm(Request $request): Response|RedirectResponse
    {
        if (! is_string($request->session()->get(self::PENDING_PHONE))) {
            return redirect()->route('portal.sign-in');
        }

        return Inertia::render('portal/Register', [
            'audience' => $request->session()->get(self::AUDIENCE, 'business'),
        ]);
    }

    public function register(Request $request, RegisterParty $register, ManagePortalCredentials $credentials): RedirectResponse
    {
        $phone = $request->session()->get(self::PENDING_PHONE);

        if (! is_string($phone)) {
            return redirect()->route('portal.sign-in');
        }

        if ($request->input('audience') === 'buyer') {
            $buyer = $request->validate([
                'person_name' => ['required', 'string', 'max:120'],
                'email' => ['nullable', 'email', 'max:180'],
            ]);

            try {
                $account = $credentials->registerBuyer($phone, $buyer['person_name'], $buyer['email'] ?? null);
            } catch (RuntimeException $e) {
                return back()->withErrors(['person_name' => $e->getMessage()]);
            }

            Auth::guard('portal')->login($account, remember: true);
            $request->session()->regenerate();
            $request->session()->forget([self::PENDING_PHONE, self::AUDIENCE]);

            return $this->home($request)->with('status', 'Welcome. Your account is ready.');
        }

        $validated = $request->validate([
            'person_name' => ['required', 'string', 'max:120'],
            'display_name' => ['required', 'string', 'max:180'],
            'kind' => ['required', 'string', 'in:individual,company'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:180'],
        ]);

        try {
            $party = $register(
                $phone,
                $validated['person_name'],
                $validated['display_name'],
                PartyKind::from($validated['kind']),
                $validated['legal_name'] ?? null,
                $validated['email'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['display_name' => $e->getMessage()]);
        }

        $account = PortalAccount::query()->where('phone', $phone)->firstOrFail();

        Auth::guard('portal')->login($account, remember: true);
        $request->session()->regenerate();
        $request->session()->forget(self::PENDING_PHONE);

        return $this->home($request)
            ->with('status', "Registered. Your code is {$party->code}.");
    }

    /** A business ID or email, and a password. See SignInWithPassword. */
    public function signInWithPassword(Request $request, SignInWithPassword $attempt): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:180'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $account = $attempt($validated['identifier'], $validated['password']);

        if (! $account instanceof PortalAccount) {
            return back()->withErrors([
                'identifier' => 'Those details do not match an account. You can always sign in with a code by SMS.',
            ])->onlyInput('identifier');
        }

        Auth::guard('portal')->login($account, $request->boolean('remember'));
        $request->session()->regenerate();
        $account->forceFill(['last_signed_in_at' => now()])->save();

        return $this->home($request);
    }

    public function forgotForm(): Response
    {
        return Inertia::render('portal/ForgotPassword');
    }

    public function resetForm(Request $request): Response|RedirectResponse
    {
        if (! is_string($request->session()->get(self::RESET_PHONE))) {
            return redirect()->route('portal.forgot-password');
        }

        return Inertia::render('portal/ResetPassword');
    }

    public function reset(Request $request, ManagePortalCredentials $credentials): RedirectResponse
    {
        $phone = $request->session()->get(self::RESET_PHONE);

        if (! is_string($phone)) {
            return redirect()->route('portal.forgot-password');
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        $account = PortalAccount::query()->where('phone', $phone)->firstOrFail();
        $credentials->setPassword($account, $validated['password'], 'reset');

        $request->session()->forget(self::RESET_PHONE);
        Auth::guard('portal')->login($account);
        $request->session()->regenerate();

        return redirect()->route('portal.dashboard')->with('status', 'Your new password is set.');
    }

    public function signOut(Request $request): RedirectResponse
    {
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.sign-in');
    }

    /**
     * After signing in: the portal page the person was on their way to, if
     * any, else the dashboard. Only a page inside the portal or Enumerate (the
     * same accounts, another front door) is honoured, so nothing another
     * sign-in remembered can send a portal account elsewhere.
     */
    private function home(Request $request): RedirectResponse
    {
        $intended = $request->session()->pull(EnsurePortalAccount::INTENDED);
        $path = is_string($intended) ? (string) parse_url($intended, PHP_URL_PATH) : '';
        $host = is_string($intended) ? parse_url($intended, PHP_URL_HOST) : null;

        $ours = str_starts_with($path, '/portal') || $path === '/enumerate' || str_starts_with($path, '/enumerate/');

        if ($ours && ($host === null || $host === $request->getHost())) {
            return redirect()->to($intended);
        }

        return redirect()->route('portal.dashboard');
    }
}
