<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Enumerate\Registry\DojahRegistry;
use App\Domain\Enumerate\Registry\FakeRegistry;
use App\Domain\Enumerate\Registry\PremblyRegistry;
use App\Domain\Enumerate\Registry\RegistryLookup;
use App\Domain\Sms\LogSms;
use App\Domain\Sms\SmsGateway;
use App\Domain\Sms\TermiiSms;
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
            'prembly' => new PremblyRegistry(
                (string) config('services.prembly.base_url'),
                (string) config('services.prembly.api_key'),
                (string) config('services.prembly.app_id'),
            ),
            default => new FakeRegistry($this->app->isProduction()),
        });

        // The same shape: a server nobody configured fails on the first code
        // it tries to send, saying which keys are missing, and never falls
        // back to writing live codes into its log.
        $this->app->bind(SmsGateway::class, fn (): SmsGateway => match (config('services.sms.driver')) {
            'termii' => new TermiiSms(
                (string) config('services.termii.base_url'),
                (string) config('services.termii.api_key'),
                (string) config('services.termii.sender_id'),
                (string) config('services.termii.channel'),
            ),
            default => new LogSms($this->app->environment(['local', 'testing'])),
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
