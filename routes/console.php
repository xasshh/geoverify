<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The SLA refund, every morning at seven, Lagos time.
|
| Early enough that a breach is settled before the customer starts their day,
| and after midnight so the date it compares against has actually turned over.
| withoutOverlapping because a slow run must not have a second copy of itself
| refunding the same orders alongside it.
*/
Schedule::command('orders:sweep-sla')
    ->dailyAt('07:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

/*
| A dispatched order whose buyer neither confirmed nor raised an issue within
| the window is released to the merchant. Before the reconciliation, so the
| morning's movements are all posted by the time it reads them.
*/
Schedule::command('orders:release-delivered')
    ->dailyAt('07:15')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

/*
| The two records of the same money, compared every morning.
|
| After the sweep rather than before it, so a refund posted at seven is inside
| the window this reads rather than arriving halfway through it. Seven days
| back each time on purpose: a webhook that failed on Friday and was retried
| into nothing over the weekend is exactly what this exists to catch, and a
| window of one day would call Monday clean.
|
| It exits non-zero on drift, which is what makes it worth scheduling: the
| failure is the notification.
*/
Schedule::command('geoverify:reconcile-ledger')
    ->dailyAt('07:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

/*
| Tier 3's daily visits, before officers head out.
|
| Six in the morning so the day's visit is on the officer's Today screen when
| they open it, and after midnight so yesterday's unfiled visits are
| yesterday's. Idempotent, so an overlap would open nothing twice; the guard
| is for the missed-day events, which should be written once.
*/
Schedule::command('enumerate:schedule-monitoring')
    ->dailyAt('06:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
