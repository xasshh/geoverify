<?php

declare(strict_types=1);

use App\Domain\Enumerate\Actions\ManageEnumerateVisits;
use App\Domain\Enumerate\Actions\ManageMonitoring;
use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\EnumerateVisit;
use App\Domain\Ledger\Actions\ReadLedgerBalances;
use App\Domain\Media\Actions\StorePhotograph;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Enumerate E3: Tier 3's daily visits.
 *
 * The clock is fixed so the calendar is known: the site visit is confirmed on
 * Monday 5 October 2026, so a 7-day period runs Tuesday 6 to Monday 12, with
 * six trading days (Sunday 11 is not one). A 7-day Tier 3 is ₦6,000; less the
 * ₦1,500 desk check, each trading day stands for ₦750.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    config()->set('services.paystack.secret', 'sk_test_marketplace');
    config()->set('services.registry.driver', 'fake');
    config()->set('geoverify.public_holidays', []);
    Storage::fake('local');
    coveredGround();
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00', config('app.timezone')));
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{request: EnumerateRequest, officer: User, supervisor: User} */
function monitored(): array
{
    $it = visitAssigned(Tier::Activity, 7);
    fileVisit($it);
    app(ManageEnumerateVisits::class)->review($it['visit'], $it['supervisor'], true, null);

    return ['request' => $it['request']->refresh(), 'officer' => $it['officer'], 'supervisor' => $it['supervisor']];
}

function onDay(string $date): void
{
    Carbon::setTestNow(Carbon::parse("{$date} 07:00", config('app.timezone')));
    app(ManageMonitoring::class)->schedule();
}

function todaysVisit(EnumerateRequest $request): EnumerateVisit
{
    return EnumerateVisit::query()
        ->where('enumerate_request_id', $request->id)
        ->where('kind', EnumerateVisit::KIND_MONITORING)
        ->where('status', EnumerateVisit::ASSIGNED)
        ->sole();
}

/** @param  array<string, mixed>  $log */
function logDay(EnumerateVisit $visit, User $officer, array $log = []): EnumerateVisit
{
    $visits = app(ManageEnumerateVisits::class);
    $visits->arrive($visit, $officer, 7.4912, 9.04244, 4.0);
    $visits->photograph($visit, $officer, UploadedFile::fake()->image('front.jpg'), 'storefront', (string) Str::uuid7(), 7.4912, 9.04244, app(StorePhotograph::class));

    return app(ManageMonitoring::class)->submitLog($visit, $officer, (string) Str::uuid7(), $log + [
        'state' => 'open',
        'opens' => '08:05',
        'closes' => '18:10',
        'staff' => 7,
        'customers' => 12,
        'activity' => 'Three supplier deliveries, steady walk-in customers.',
    ], null);
}

/** Log a day and have the supervisor accept it. */
function logAndAccept(EnumerateRequest $request, User $officer, User $supervisor, string $date): void
{
    onDay($date);
    app(ManageEnumerateVisits::class)->review(logDay(todaysVisit($request), $officer), $supervisor, true, null);
}

it('starts the period the day after the location is confirmed, with the officer who found it', function () {
    $it = monitored();

    expect($it['request']->status)->toBe(RequestStatus::Monitoring)
        ->and($it['request']->monitoring_starts_on?->toDateString())->toBe('2026-10-06')
        ->and($it['request']->monitoring_ends_on?->toDateString())->toBe('2026-10-12')
        ->and($it['request']->monitoring_officer_id)->toBe($it['officer']->id)
        ->and(app(ManageMonitoring::class)->tradingDays($it['request']))->toHaveCount(6);
});

it('opens one visit on each trading day, and none on Sunday', function () {
    $it = monitored();
    $monitoring = app(ManageMonitoring::class);

    expect($monitoring->schedule())->toBe(['opened' => 0, 'missed' => 0]);

    onDay('2026-10-06');
    expect(app(ManageMonitoring::class)->schedule())->toBe(['opened' => 0, 'missed' => 0]);

    $day1 = todaysVisit($it['request']);
    expect($day1->day_number)->toBe(1)
        ->and($day1->agent_id)->toBe($it['officer']->id)
        ->and($day1->ward_id)->not->toBeNull();

    // On the officer's Today, as a daily visit.
    $this->actingAs($it['officer'])->get('/field')
        ->assertInertia(fn ($page) => $page->where('day.jobs.0.kind', 'daily')
            ->where('day.jobs.0.orderRef', "{$it['request']->reference} · day 1 of 7"));

    logAndAccept($it['request'], $it['officer'], $it['supervisor'], '2026-10-06');

    onDay('2026-10-11');
    expect(EnumerateVisit::query()->whereDate('visit_date', '2026-10-11')->exists())->toBeFalse();
});

it('marks a day nobody filed as missed, and refuses a log for it afterwards', function () {
    $it = monitored();
    onDay('2026-10-06');
    $day1 = todaysVisit($it['request']);

    onDay('2026-10-07');

    expect($day1->refresh()->status)->toBe(EnumerateVisit::MISSED)
        ->and(todaysVisit($it['request'])->day_number)->toBe(2);

    expect(fn () => logDay($day1, $it['officer']))->toThrow(RuntimeException::class, 'That day is over');
});

it('asks a daily log for what was seen, the hours as times, and a photograph', function () {
    $it = monitored();
    onDay('2026-10-06');
    $visit = todaysVisit($it['request']);
    $monitoring = app(ManageMonitoring::class);
    $uuid = (string) Str::uuid7();

    expect(fn () => $monitoring->submitLog($visit, $it['officer'], $uuid, ['state' => 'open', 'activity' => 'Busy.'], null))
        ->toThrow(RuntimeException::class, 'Say in a sentence');
    expect(fn () => $monitoring->submitLog($visit, $it['officer'], $uuid, ['state' => 'open', 'opens' => '8am', 'activity' => 'Busy all morning.'], null))
        ->toThrow(RuntimeException::class, 'Write the hours as 08:05');
    expect(fn () => $monitoring->submitLog($visit, $it['officer'], $uuid, ['state' => 'open', 'activity' => 'Busy all morning.'], null))
        ->toThrow(RuntimeException::class, 'Record your arrival');

    app(ManageEnumerateVisits::class)->arrive($visit, $it['officer'], 7.4912, 9.0421, 5.0);

    expect(fn () => $monitoring->submitLog($visit, $it['officer'], $uuid, ['state' => 'open', 'activity' => 'Busy all morning.'], null))
        ->toThrow(RuntimeException::class, 'Take at least one photograph');

    // A daily visit's arrival does not move the request.
    expect($it['request']->refresh()->status)->toBe(RequestStatus::Monitoring);
});

it('keeps no hours or counts for a day the business was closed', function () {
    $it = monitored();
    onDay('2026-10-06');

    $logged = logDay(todaysVisit($it['request']), $it['officer'], ['state' => 'closed', 'activity' => 'Shutter down all day; neighbour says stock-taking.']);

    expect($logged->log)->toMatchArray(['state' => 'closed', 'opens' => null, 'closes' => null, 'staff' => null, 'customers' => null]);
});

it('files a daily log through the field endpoint, and refuses a checklist for it', function () {
    $it = monitored();
    onDay('2026-10-06');
    $visit = todaysVisit($it['request']);
    $base = "/api/field/visits/{$visit->id}";

    $this->actingAs($it['officer'])->postJson("{$base}/arrive", ['latitude' => 9.0421, 'longitude' => 7.4912])->assertOk();
    $this->post("{$base}/photos", ['client_uuid' => (string) Str::uuid7(), 'angle' => 'storefront', 'photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();

    $this->postJson("{$base}/report", ['report_uuid' => (string) Str::uuid7(), 'answers' => ['premises_found' => ['passed' => true]]])
        ->assertStatus(422);

    $this->postJson("{$base}/report", [
        'report_uuid' => (string) Str::uuid7(),
        'log' => ['state' => 'low', 'opens' => '09:00', 'closes' => '15:00', 'staff' => 5, 'activity' => 'Open but quiet, supply truck delayed.'],
    ])->assertOk();

    expect($visit->refresh()->status)->toBe(EnumerateVisit::SUBMITTED)
        ->and($visit->log['state'])->toBe('low');
});

it('shows the requester the calendar and accepted logs, with photographs counted and not shown', function () {
    $it = monitored();
    logAndAccept($it['request'], $it['officer'], $it['supervisor'], '2026-10-06');
    onDay('2026-10-07');
    logDay(todaysVisit($it['request']), $it['officer']);

    $response = $this->actingAs($it['request']->requester()->firstOrFail(), 'portal')
        ->get("/enumerate/verifications/{$it['request']->reference}");

    $response->assertInertia(fn ($page) => $page
        ->has('request.monitoring.calendar', 7)
        ->where('request.monitoring.calendar.0.state', 'open')
        ->where('request.monitoring.calendar.1.state', 'pending')
        ->where('request.monitoring.calendar.1.today', true)
        ->where('request.monitoring.calendar.5.state', 'no_visit')
        ->where('request.monitoring.calendar.5.weekday', 'Su')
        ->where('request.monitoring.calendar.6.state', 'upcoming')
        ->where('request.monitoring.dayToday', 2)
        ->has('request.monitoring.entries', 1)
        ->where('request.monitoring.entries.0.activity', 'Three supplier deliveries, steady walk-in customers.')
        ->where('request.monitoring.entries.0.photos', 1)
        ->where('request.statusNote', 'Day 2 of 7'));

    expect(json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR))->not->toContain('media/file');
});

it('redoes a struck-off log on the same day, and leaves a past one without a log', function () {
    $it = monitored();
    onDay('2026-10-06');
    $visits = app(ManageEnumerateVisits::class);

    $visits->review(logDay(todaysVisit($it['request']), $it['officer']), $it['supervisor'], false, 'That is the shop next door, check the sign.');

    $redo = todaysVisit($it['request']);
    expect($redo->day_number)->toBe(1)
        ->and($redo->visit_date?->toDateString())->toBe('2026-10-06');

    $filed = logDay($redo, $it['officer']);
    onDay('2026-10-07');
    $visits->review($filed, $it['supervisor'], false, 'The photograph shows a different street.');

    expect(EnumerateVisit::query()->whereDate('visit_date', '2026-10-06')->where('status', EnumerateVisit::ASSIGNED)->exists())->toBeFalse();
});

it('moves today’s visit to a new officer who has not been sent yet', function () {
    $it = monitored();
    onDay('2026-10-06');
    $other = person(Role::Officer, 'Ngozi Kalu');

    app(ManageMonitoring::class)->reassign($it['request'], $other, $it['supervisor']);

    expect(todaysVisit($it['request'])->agent_id)->toBe($other->id)
        ->and($it['request']->refresh()->monitoring_officer_id)->toBe($other->id);
});

it('closes after the period, earning the logged days and returning the missed ones', function () {
    $it = monitored();
    $wallet = fn (): int => enumerateWalletNaira($it['request']->requester()->firstOrFail());
    $before = $wallet();

    // Four of six trading days logged; Friday and Saturday nobody went.
    foreach (['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-12'] as $date) {
        logAndAccept($it['request'], $it['officer'], $it['supervisor'], $date);
    }

    foreach (['2026-10-09', '2026-10-10'] as $date) {
        onDay($date);
    }

    expect(fn () => app(ManageMonitoring::class)->close($it['request'], $it['supervisor']))
        ->toThrow(RuntimeException::class, 'Monitoring runs until 12 Oct');

    onDay('2026-10-13');
    $result = app(ManageMonitoring::class)->close($it['request'], $it['supervisor']);

    expect($result)->toBe(['missed' => 2, 'returnedMinor' => 150_000])
        ->and($wallet())->toBe($before + 1_500)
        ->and($it['request']->refresh()->status)->toBe(RequestStatus::Completed)
        ->and($it['request']->monitoring_missed_days)->toBe(2)
        ->and(enumerateHeld($it['request']))->toBe(0)
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();

    $this->actingAs($it['request']->requester()->firstOrFail(), 'portal')
        ->get("/enumerate/verifications/{$it['request']->reference}")
        ->assertInertia(fn ($page) => $page->where('request.monitoring.returnedMinor', 150_000)
            ->where('request.monitoring.calendar.3.state', 'missed')
            ->where('request.steps.4.state', 'done'));
});

it('will not close while a daily log waits for review', function () {
    $it = monitored();
    onDay('2026-10-12');
    logDay(todaysVisit($it['request']), $it['officer']);

    Carbon::setTestNow(Carbon::parse('2026-10-13 07:00', config('app.timezone')));

    expect(fn () => app(ManageMonitoring::class)->close($it['request'], $it['supervisor']))
        ->toThrow(RuntimeException::class, 'still waiting');
});

it('closes monitoring from the console, and schedules from the command line', function () {
    $it = monitored();

    Carbon::setTestNow(Carbon::parse('2026-10-06 06:00', config('app.timezone')));
    $this->artisan('enumerate:schedule-monitoring')->expectsOutputToContain('1 daily visit opened')->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-10-13 07:00', config('app.timezone')));
    $this->artisan('enumerate:schedule-monitoring')->expectsOutputToContain('1 missed')->assertSuccessful();

    $this->actingAs($it['supervisor'])->get('/console/enumerate-visits')
        ->assertInertia(fn ($page) => $page->where('requests.0.monitoring.ended', true)
            ->where('requests.0.monitoring.tradingDays', 6));

    $this->post("/console/enumerate-visits/requests/{$it['request']->reference}/close")->assertSessionHasNoErrors();

    expect($it['request']->refresh()->status)->toBe(RequestStatus::Completed)
        ->and($it['request']->monitoring_missed_days)->toBe(6);
});
