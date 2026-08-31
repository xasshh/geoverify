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

    'tier_freshness' => [
        // Up to here, the register presents a tier without qualification.
        'current_months' => (int) env('GEOVERIFY_TIER_CURRENT_MONTHS', 12),

        // Past here it is shown as stale: still established, and openly old.
        'stale_months' => (int) env('GEOVERIFY_TIER_STALE_MONTHS', 24),
    ],

];
