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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
