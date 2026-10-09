<?php

declare(strict_types=1);

use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Investment\Models\InvestorUser;
use App\Domain\Party\Models\PortalAccount;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        /*
         * The portal's own guard.
         *
         * Parties do not authenticate as staff. Sharing the web guard would
         * mean one session could be either an officer or a shop owner, and
         * every middleware in the field platform would have to ask which. Two
         * guards means a portal session can never satisfy `supervises`, by
         * construction rather than by check.
         */
        'portal' => [
            'driver' => 'session',
            'provider' => 'portal_accounts',
        ],

        /*
         * The commissioning client's own guard.
         *
         * Third of three, and for the same reason as the second. An NRS
         * administrator is not staff: `users` is the staff table and Role is
         * never widened to admit somebody who does not work here. Separate
         * guards mean a client session can never satisfy `supervises` or
         * `administers`, by construction rather than by check.
         */
        'client' => [
            'driver' => 'session',
            'provider' => 'client_users',
        ],

        /*
         * Investors, fourth of four and for the same reason again. They act for
         * an organisation that is vetted before it reads a dossier, and a
         * session here can never satisfy the portal, the client or the console.
         */
        'investor' => [
            'driver' => 'session',
            'provider' => 'investor_users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'portal_accounts' => [
            'driver' => 'eloquent',
            'model' => PortalAccount::class,
        ],

        'client_users' => [
            'driver' => 'eloquent',
            'model' => ClientUser::class,
        ],

        'investor_users' => [
            'driver' => 'eloquent',
            'model' => InvestorUser::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        // Portal accounts reset by email with their own token table, so a
        // member of staff and a business owner sharing an address never share
        // a token row.
        'portal_accounts' => [
            'provider' => 'portal_accounts',
            'table' => 'portal_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        // Investors reset by email, on their own token table: a token issued
        // for an investor must never be redeemable against a staff account
        // that happens to share the address.
        'investor_users' => [
            'provider' => 'investor_users',
            'table' => 'investor_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
