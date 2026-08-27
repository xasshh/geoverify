<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Actions\RegisterParty;
use App\Domain\Party\Actions\RequestSignInCode;
use App\Domain\Party\Actions\VerifySignInCode;
use App\Domain\Party\Enums\PartyKind;
use App\Domain\Party\Models\PortalAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        ]);

        try {
            $sent = $codes($validated['phone'], $request->ip());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['phone' => $e->getMessage()]);
        }

        return redirect()
            ->route('portal.verify')
            ->with('portal.phone', $sent['phone'])
            ->with('portal.masked', $sent['masked']);
    }

    public function verifyForm(Request $request): Response|RedirectResponse
    {
        $phone = $request->session()->get('portal.phone');

        if (! is_string($phone)) {
            return redirect()->route('portal.sign-in');
        }

        // Kept for the life of the attempt, so a failed code does not send the
        // person back to type their number again.
        $request->session()->keep(['portal.phone', 'portal.masked']);

        return Inertia::render('portal/VerifyCode', [
            'masked' => $request->session()->get('portal.masked'),
        ]);
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
            $request->session()->keep(['portal.phone', 'portal.masked']);

            return back()->withErrors(['code' => $e->getMessage()]);
        }

        if ($result['account'] instanceof PortalAccount) {
            Auth::guard('portal')->login($result['account'], remember: true);
            $request->session()->regenerate();

            return redirect()->route('portal.dashboard');
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

        return Inertia::render('portal/Register');
    }

    public function register(Request $request, RegisterParty $register): RedirectResponse
    {
        $phone = $request->session()->get(self::PENDING_PHONE);

        if (! is_string($phone)) {
            return redirect()->route('portal.sign-in');
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

        return redirect()
            ->route('portal.dashboard')
            ->with('status', "Registered. Your code is {$party->code}.");
    }

    public function signOut(Request $request): RedirectResponse
    {
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.sign-in');
    }
}
