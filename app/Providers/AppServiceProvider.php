<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->trustTheProxyInFront();
    }

    /**
     * Believe X-Forwarded-* only from a proxy that has been named.
     *
     * Set here rather than in bootstrap/app.php because the middleware closure
     * there runs before the config repository is bound, and reading env()
     * directly would go quiet the moment config is cached in production, which
     * is exactly where this matters.
     *
     * See config/app.php for why the default is to trust nothing.
     */
    private function trustTheProxyInFront(): void
    {
        $proxies = config('app.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return;
        }

        TrustProxies::at(
            $proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)),
        );
    }
}
