<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Enumerate\Actions\ReadEnumeratePrices;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public front page: what GeoVerify is, and the doors into it.
 *
 * Every door leads somewhere that works today (the directory, Enumerate, the
 * business portal, the investor portal, the field network), and the quick
 * check searches the public directory, so it shows only what the directory
 * already shows: nothing an unclaimed business has not put on the street.
 */
final class HomeController
{
    public function __invoke(ReadEnumeratePrices $prices): Response
    {
        return Inertia::render('public/Home', [
            'prices' => $prices->list(),
        ]);
    }
}
