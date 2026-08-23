<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Officers and supervisors start their day on different screens, so where
        // login lands cannot be a single configured path.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => Inertia::render('auth/Login', [
            'status' => session('status'),
        ]));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/ForgotPassword', [
            'status' => session('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));

        /**
         * A suspended account cannot sign in, and is told so plainly rather than
         * being given a wrong password message it would waste time on. Suspension
         * is not deletion: their captures stay attributable.
         */
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', (string) $request->input('email'))
                ->first();

            if (! $user instanceof User) {
                return null;
            }

            if (! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            return $user->isActive() ? $user : null;
        });

        // Credential stuffing against a register of government field staff is a
        // realistic threat, so the limit is per email and per address together.
        RateLimiter::for('login', function (Request $request): Limit {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->session()->get('login.id')));
    }
}
