<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * The browser that prints the evidence pack.
     *
     * The pack is rendered by a real engine because the gate is that it looks
     * like a survey document, and the alternatives cannot draw the Presence
     * Mark, the contact sheet or a page break the way the design needs. This
     * project already depends on tippecanoe, PostGIS and h3-pg, so one more
     * binary is the established shape rather than a new kind of debt.
     *
     * Left null in most environments and discovered from the usual places. Set
     * it when the binary is somewhere unusual, or to pin which one is used.
     */
    'chromium' => [
        'binary' => env('CHROMIUM_BINARY'),
        'timeout' => (int) env('CHROMIUM_TIMEOUT', 120),

        /*
         * Where the printing browser should fetch the document from.
         *
         * Left null and derived from the request, which is right whenever the
         * application answers on the same port it is reached on. Set it behind
         * a proxy that terminates TLS somewhere the host cannot follow.
         */
        'base_url' => env('CHROMIUM_BASE_URL'),
    ],

    /*
     * The payment provider.
     *
     * Paystack because the customer is a Nigerian business paying in naira from
     * a Nigerian bank, and card is the smaller half of how that is done: bank
     * transfer and USSD carry most of it, and a provider without them locally
     * would exclude a good part of the audience.
     *
     * The secret is what signs the webhook, and the webhook is the only thing
     * this application believes about money. A missing secret must therefore
     * fail loudly rather than default to trusting the caller, which is what an
     * empty-string default would quietly do.
     */
    'paystack' => [
        'secret' => env('PAYSTACK_SECRET_KEY'),
        'public' => env('PAYSTACK_PUBLIC_KEY'),
    ],

    /*
     * The official registers (CAC, FIRS), through a licensed provider.
     *
     * `fake` answers from a fixed list and refuses to start in production, so
     * a server nobody configured fails loudly rather than issuing reports from
     * made-up records. Dojah's sandbox and production differ only in the base
     * URL and the keys.
     */
    'registry' => [
        'driver' => env('REGISTRY_DRIVER', 'fake'),
    ],

    'dojah' => [
        'base_url' => env('DOJAH_BASE_URL', 'https://sandbox.dojah.io'),
        'app_id' => env('DOJAH_APP_ID', ''),
        'secret' => env('DOJAH_SECRET_KEY', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
