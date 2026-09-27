<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invest;

use App\Domain\Investment\Actions\ReadInvestorOverview;
use App\Domain\Investment\Actions\ReadOpportunities;
use App\Domain\Investment\Actions\RequestInvestorAccess;
use App\Domain\Investment\Enums\InvestorKind;
use App\Domain\Investment\Models\InvestorUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Signing in, and asking for access, on the investor guard.
 *
 * Email and password, like the client, because an investor is a professional
 * at a desk with no business phone on any record of ours to prove.
 */
final class SignInController
{
    /**
     * The sign-in page, and the panel beside it.
     *
     * The panel shows the real register: the verified count per state over the
     * directory-visible population, and the best-scoring published
     * opportunity. Anonymised, because nobody on this page has signed in, let
     * alone passed KYC: a sector, a state and a score, never a name.
     */
    public function show(ReadInvestorOverview $overview, ReadOpportunities $opportunities): Response
    {
        $read = $overview(0);
        $top = $opportunities->rows(0, [], 50)[0] ?? null;

        return Inertia::render('invest/SignIn', [
            'coverage' => [
                'states' => $read['states'],
                'statesCovered' => count(array_filter($read['states'], static fn (array $s): bool => $s['verified'] > 0)),
            ],
            'featured' => $top === null ? null : [
                'score' => $top['score'],
                'sector' => $top['sector'] ?? $top['activity'] ?? 'Verified business',
                'state' => $top['state'],
                'lastVerified' => $top['lastVerified'],
            ],
        ]);
    }

    public function forgotForm(): Response
    {
        return Inertia::render('invest/ForgotPassword');
    }

    /**
     * Always the same answer, whether or not the address has an account, so
     * the form cannot be used to learn which firms are here.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        Password::broker('investor_users')->sendResetLink(['email' => mb_strtolower($validated['email'])]);

        return back()->with('status', 'If that address has an investor account, a reset link is on its way. It works for 60 minutes.');
    }

    public function resetForm(Request $request, string $token): Response
    {
        return Inertia::render('invest/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $status = Password::broker('investor_users')->reset(
            ['email' => mb_strtolower($validated['email'])] + $validated,
            static function (InvestorUser $user, string $password): void {
                $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
                $user->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'That link has expired or was already used. Ask for a new one.']);
        }

        return to_route('invest.sign-in')->with('status', 'Your password is reset. Sign in with it now.');
    }

    public function ssoForm(): Response
    {
        return Inertia::render('invest/Sso');
    }

    /**
     * Single sign-on is set up per organisation, against its identity
     * provider, when it asks. Until an organisation's domain is configured the
     * answer is said plainly rather than a redirect to a provider that does
     * not know them.
     */
    public function sso(Request $request): HttpResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $domain = mb_strtolower(substr((string) strrchr($validated['email'], '@'), 1));

        /** @var array<string, string> $providers */
        $providers = (array) config('geoverify.investor_sso', []);

        if (! array_key_exists($domain, $providers)) {
            return back()->withErrors([
                'email' => "Single sign-on is not set up for {$domain} yet. Sign in with your email and password, or ask us to connect your firm's identity provider.",
            ])->withInput();
        }

        // See OrderController::pay: an XHR cannot follow a redirect off-site.
        return Inertia::location($providers[$domain]);
    }

    public function signIn(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials['email'] = mb_strtolower($credentials['email']);

        if (! Auth::guard('investor')->attempt($credentials, $request->boolean('remember'))) {
            // One message for both, so the form cannot be used to learn which
            // firms have an account here.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an account.',
            ]);
        }

        $account = Auth::guard('investor')->user();

        if (! $account instanceof InvestorUser || ! $account->canSignIn()) {
            Auth::guard('investor')->logout();

            throw ValidationException::withMessages([
                'email' => 'That account is not active. Contact us if you think this is wrong.',
            ]);
        }

        $request->session()->regenerate();
        $account->forceFill(['last_signed_in_at' => now()])->save();

        return to_route('invest.overview');
    }

    public function requestForm(): Response
    {
        return Inertia::render('invest/RequestAccess', [
            'kinds' => InvestorKind::options(),
        ]);
    }

    public function request(Request $request, RequestInvestorAccess $requestAccess): RedirectResponse
    {
        $input = $request->validate([
            'organisation' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::enum(InvestorKind::class)],
            'website' => ['nullable', 'url', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', Rule::unique('investor_users', 'email')],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $user = $requestAccess($input);

        Auth::guard('investor')->login($user);
        $request->session()->regenerate();

        return to_route('invest.overview')->with(
            'status',
            'Your request is in. You can explore the overview now; dossiers and data rooms open once we have verified your organisation.',
        );
    }

    public function signOut(Request $request): RedirectResponse
    {
        Auth::guard('investor')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('invest.sign-in');
    }
}
