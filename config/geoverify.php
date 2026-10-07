<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tier freshness
    |--------------------------------------------------------------------------
    |
    | How long a verified tier stays believable before the register starts
    | saying so. A location verified in 2024 is not a lie, but presenting it the
    | same way as one verified last month would be: businesses move, close and
    | change hands, and the whole value of this register is that it says how well
    | it knows what it is telling you.
    |
    | Configurable because it is a commercial judgement rather than a fact. A
    | mandate over a stable industrial estate and one over a market where stalls
    | turn over every quarter do not age at the same rate, and a client who wants
    | a tighter window should be able to have one without a deployment.
    |
    | Expressed in months, counted from the date the tier was established.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Public holidays
    |--------------------------------------------------------------------------
    |
    | The SLA is stated in working days, and working days in Nigeria means
    | weekends plus these. Held as data because half of them move: the Islamic
    | dates follow the lunar calendar and are announced by the Federal
    | Government a few days ahead, so this list has to be maintained rather than
    | computed, and a year with no entries is a year whose SLAs are wrong.
    |
    | Dates for a year that is not listed fall back to weekends alone, which
    | errs towards promising sooner rather than later. That is the safer
    | direction: it costs us a refund rather than costing a customer a promise
    | we quietly moved.
    |
    */

    'public_holidays' => [
        '2026-01-01', // New Year's Day
        '2026-03-20', // Eid al-Fitr, announced
        '2026-03-21', // Eid al-Fitr, second day
        '2026-04-03', // Good Friday
        '2026-04-06', // Easter Monday
        '2026-05-01', // Workers' Day
        '2026-05-27', // Eid al-Adha, announced
        '2026-05-28', // Eid al-Adha, second day
        '2026-06-12', // Democracy Day
        '2026-08-25', // Eid al-Mawlid, announced
        '2026-10-01', // Independence Day
        '2026-12-25', // Christmas Day
        '2026-12-26', // Boxing Day
    ],

    'tier_freshness' => [
        // Up to here, the register presents a tier without qualification.
        'current_months' => (int) env('GEOVERIFY_TIER_CURRENT_MONTHS', 12),

        // Past here it is shown as stale: still established, and openly old.
        'stale_months' => (int) env('GEOVERIFY_TIER_STALE_MONTHS', 24),
    ],

    /*
    | Single sign-on for investor organisations, by email domain: the URL that
    | starts the firm's own identity provider flow. Empty until a firm asks.
    */
    'investor_sso' => [],

    /*
    | The officer's Today screen. Nothing in the register says how many captures
    | a day is reasonable on a given ground, so the target is stated here.
    */
    'field' => [
        'daily_capture_target' => (int) env('GEOVERIFY_DAILY_CAPTURE_TARGET', 25),
    ],

    /*
    | The merchant hub's money (M2). Kobo throughout.
    |
    | The mockups leave the inspection fee, the visit fee and the commission as
    | placeholders, and so does this file: a price nobody has decided is null,
    | and a service with no price cannot be bought. Commission is in basis
    | points of the goods only, never of delivery or of a fee the buyer paid us.
    |
    | release_after_days is how long after dispatch a buyer who has neither
    | confirmed nor raised an issue has to do one or the other, before the
    | merchant is paid anyway. Without it a buyer who forgets holds a stranger's
    | money for ever.
    */
    'commerce' => [
        'delivery_fee_minor' => (int) env('GEOVERIFY_DELIVERY_FEE_MINOR', 350_000),
        // Only a number is a price. An empty `KEY=` in .env reads as '', which
        // cast to int would make an undecided fee a free one.
        'inspection_fee_minor' => is_numeric(env('GEOVERIFY_INSPECTION_FEE_MINOR')) ? (int) env('GEOVERIFY_INSPECTION_FEE_MINOR') : null,
        'visit_fee_minor' => is_numeric(env('GEOVERIFY_VISIT_FEE_MINOR')) ? (int) env('GEOVERIFY_VISIT_FEE_MINOR') : null,
        'commission_basis_points' => (int) env('GEOVERIFY_COMMISSION_BASIS_POINTS', 0),
        'release_after_days' => (int) env('GEOVERIFY_RELEASE_AFTER_DAYS', 7),
        'minimum_payout_minor' => (int) env('GEOVERIFY_MINIMUM_PAYOUT_MINOR', 100_000),
    ],

    /*
     * Satellite imagery for area capture.
     *
     * Built from Sentinel-2 through the Earth Search STAC catalogue on AWS open
     * data: no account, Copernicus licence (free, commercial use allowed,
     * offline copies allowed, credit required). 10 m pixels, so the archive
     * stops at zoom 14 and the map overzooms past it.
     *
     * The pipeline shells out to GDAL and to the pmtiles CLI, neither of which
     * is a PHP dependency; their paths are here so a server that keeps them
     * somewhere else says so in one place.
     */
    'imagery' => [
        'stac_url' => env('GEOVERIFY_STAC_URL', 'https://earth-search.aws.element84.com/v1'),
        'collection' => 'sentinel-2-l2a',
        // A scene cloudier than this over the tile is not worth the download.
        'max_cloud_pct' => (float) env('GEOVERIFY_IMAGERY_MAX_CLOUD', 10),
        // How far back to look. Two years covers two dry seasons, which is
        // when the Nigerian sky is clear enough to see the ground.
        'lookback_days' => (int) env('GEOVERIFY_IMAGERY_LOOKBACK_DAYS', 730),
        // A guard against asking for a whole state by accident: past this a
        // pack is too large for a phone to hold.
        'max_area_km2' => (int) env('GEOVERIFY_IMAGERY_MAX_AREA_KM2', 15_000),
        'gdal_bin' => env('GDAL_BIN', ''),
        'pmtiles_binary' => env('PMTILES_BINARY', 'pmtiles'),
        'timeout_seconds' => (int) env('GEOVERIFY_IMAGERY_TIMEOUT', 3600),
        'licence_note' => 'Contains modified Copernicus Sentinel data, processed by GeoVerify.',
        // The long-running connection where the queue is Redis (production);
        // whatever the application otherwise uses anywhere else, so a
        // development machine or a test run needs no second worker.
        'queue_connection' => env(
            'GEOVERIFY_IMAGERY_QUEUE_CONNECTION',
            env('QUEUE_CONNECTION') === 'redis' ? 'redis-long' : env('QUEUE_CONNECTION', 'database'),
        ),
    ],
];
