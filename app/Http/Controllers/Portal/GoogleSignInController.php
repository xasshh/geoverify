<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Party\Actions\SignInWithGoogle;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePortalAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Continue with Google" for portal (and so Enumerate) accounts.
 *
 * Off until the server has a Google client id and secret. The OAuth state is
 * kept in the session by Socialite, so a callback nobody started here is
 * refused rather than signed in.
 */
final class GoogleSignInController extends Controller
{
    public function redirect(Request $request): SymfonyRedirect|RedirectResponse
    {
        abort_unless(SignInWithGoogle::configured(), 404);

        if ($request->query('next') === 'enumerate') {
            $request->session()->put(EnsurePortalAccount::INTENDED, url('/enumerate'));
        }

        /** @var \Laravel\Socialite\Two\GoogleProvider $google */
        $google = Socialite::driver('google');

        return $google->scopes(['openid', 'email', 'profile'])->redirect();
    }

    public function callback(Request $request, SignInWithGoogle $signIn): RedirectResponse
    {
        abort_unless(SignInWithGoogle::configured(), 404);

        try {
            /** @var User $google */
            $google = Socialite::driver('google')->user();
            $account = $signIn(
                (string) $google->getId(),
                (string) $google->getEmail(),
                (bool) ($google->getRaw()['email_verified'] ?? $google->user['email_verified'] ?? false),
                (string) $google->getName(),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('enumerate.sign-in')->withErrors(['identifier' => $e->getMessage()]);
        } catch (InvalidStateException) {
            return redirect()->route('enumerate.sign-in')->withErrors(['identifier' => 'That Google sign in expired. Press "Continue with Google" again.']);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('enumerate.sign-in')->withErrors(['identifier' => 'Google sign in did not work just now. Try again, or use your email and password.']);
        }

        $intended = $request->session()->pull(EnsurePortalAccount::INTENDED);

        Auth::guard('portal')->login($account, remember: true);
        $request->session()->regenerate();

        $path = is_string($intended) ? (string) parse_url($intended, PHP_URL_PATH) : '';

        return str_starts_with($path, '/portal') || str_starts_with($path, '/enumerate')
            ? redirect()->to($path)
            : redirect()->route('portal.dashboard');
    }
}
