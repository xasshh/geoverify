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
