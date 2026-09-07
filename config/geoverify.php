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

];
