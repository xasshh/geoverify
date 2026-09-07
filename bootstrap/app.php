<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAdministers;
use App\Http\Middleware\EnsureCapturesInTheField;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsurePortalAccount;
use App\Http\Middleware\EnsureSupervises;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * The payment provider does not carry a session and cannot hold a CSRF
         * token. Its authenticity is established by the signature on the body,
         * which HandlePaymentWebhook checks before reading a single field out
         * of it, and which is a stronger guarantee than a token would be.
         *
         * Named explicitly rather than exempting a prefix, so a second route
         * cannot join this exemption by being filed in the same folder.
         */
        $middleware->validateCsrfTokens(except: [
            'webhooks/paystack',
        ]);

        $middleware->alias([
            'supervises' => EnsureSupervises::class,
            'field' => EnsureCapturesInTheField::class,
            'portal' => EnsurePortalAccount::class,
            'administers' => EnsureAdministers::class,
            'client' => EnsureClientUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
