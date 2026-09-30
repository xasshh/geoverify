<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Enumerate\Registry\DojahRegistry;
use App\Domain\Enumerate\Registry\FakeRegistry;
use App\Domain\Enumerate\Registry\RegistryLookup;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolved when first asked for, so a server without registry keys
        // still boots and only the lookup itself says what is missing.
        $this->app->bind(RegistryLookup::class, fn (): RegistryLookup => match (config('services.registry.driver')) {
            'dojah' => new DojahRegistry(
                (string) config('services.dojah.base_url'),
                (string) config('services.dojah.app_id'),
                (string) config('services.dojah.secret'),
            ),
            default => new FakeRegistry($this->app->isProduction()),
        });
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
