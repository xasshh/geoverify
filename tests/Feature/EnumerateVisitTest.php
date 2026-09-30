<?php

declare(strict_types=1);

use App\Domain\Enumerate\Actions\DecideDeskCheck;
use App\Domain\Enumerate\Actions\ManageEnumerateVisits;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Enumerate E2: the site visit, from the supervisor's pin to what the
 * requester is shown.
 *
 * The things worth proving: the money moves once, on acceptance; a visit sent
 * back is kept and a new one opened; and the requester sees the distance, the
 * findings and the outside of the building, never the inside and never a
 * coordinate (CLAUDE.md, the second exception to "an officer's never leave").
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    config()->set('services.paystack.secret', 'sk_test_marketplace');
    config()->set('services.registry.driver', 'fake');
    Storage::fake('local');
    coveredGround();
});

/** The Garki pin, as a supervisor would paste it. */
const VISIT_PIN = '9.0421, 7.4912';

/** A request of this tier that passed its desk check and waits for an officer. */
function awaitingAgent(Tier $tier = Tier::Location, ?int $days = null): EnumerateRequest
{
    $account = enumerateRequester();
    enumerateFund($account, 20_000);

    $request = enumerateCheck($account, $tier, $days);

    return app(DecideDeskCheck::class)($request, person(Role::Supervisor), true, null)->refresh();
}

/** @return array{request: EnumerateRequest, visit: EnumerateVisit, officer: User, supervisor: User} */
function visitAssigned(Tier $tier = Tier::Location, ?int $days = null): array
{
    $request = awaitingAgent($tier, $days);
    $officer = person(Role::Officer, 'Musa Danjuma');
    $officer->forceFill(['staff_ref' => 'FA-0231'])->save();
    $supervisor = person(Role::Supervisor, 'Hauwa Ibrahim');

    $visit = app(ManageEnumerateVisits::class)->assign($request, $officer, $supervisor, 9.0421, 7.4912);

    return ['request' => $request->refresh(), 'visit' => $visit, 'officer' => $officer, 'supervisor' => $supervisor];
}

/**
 * The officer's side, through the same action the endpoints call: arrival
 * about 38 m north of the pin, three photographs, the report. The endpoints
 * themselves are exercised below and by the offline browser spec.
 *
 * @param  array{visit: EnumerateVisit, officer: User}  $it
 */
function fileVisit(array $it, bool $allYes = true): void
{
    $visits = app(ManageEnumerateVisits::class);
    $visits->arrive($it['visit'], $it['officer'], 7.4912, 9.04244, 4.0);

    foreach (['storefront', 'signage', 'interior'] as $angle) {
        $visits->photograph(
            $it['visit'],
            $it['officer'],
            UploadedFile::fake()->image("{$angle}.jpg", 800, 600),
            $angle,
            (string) Str::uuid7(),
            7.4912,
            9.04244,
            app(StorePhotograph::class),
        );
    }

    $answers = [];

    foreach (array_keys(EnumerateVisit::CHECKLIST) as $key) {
        $answers[$key] = ['passed' => $allYes, 'detail' => null];
    }

    $answers['open_during_visit'] = ['passed' => true, 'detail' => 'open, 6 staff seen'];

    $visits->submit($it['visit'], $it['officer'], (string) Str::uuid7(), $answers, 'Second entrance at the back, unmarked.');
}

it('runs a Tier 2 from the pin to a confirmed location, earning the price once', function () {
    $it = visitAssigned();

    expect($it['request']->status)->toBe(RequestStatus::AgentAssigned)
        ->and($it['visit']->area())->toBe('Garki 1, Abuja Municipal');

    // It is on the officer's Today, pointing at its own page.
    $this->actingAs($it['officer'])->get('/field')
        ->assertInertia(fn ($page) => $page->where('day.jobs.0.kind', 'verification')
            ->where('day.jobs.0.href', "/field/visits/{$it['visit']->id}"));
    $this->get("/field/visits/{$it['visit']->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('field/Visit')->where('visit.reference', $it['request']->reference));

    fileVisit($it);

    $visit = $it['visit']->refresh();
    expect($visit->status)->toBe(EnumerateVisit::SUBMITTED)
        ->and($visit->arrival_distance_m)->toBeGreaterThan(30.0)->toBeLessThan(45.0)
        ->and($visit->photos()->count())->toBe(3)
        ->and($it['request']->refresh()->status)->toBe(RequestStatus::OnSite)
        ->and(enumerateHeld($it['request']))->toBe(5_000_00);

    app(ManageEnumerateVisits::class)->review($visit, $it['supervisor'], true, null);

    $request = $it['request']->refresh();
    expect($request->status)->toBe(RequestStatus::Completed)
        ->and($request->completed_at)->not->toBeNull()
        ->and(enumerateHeld($request))->toBe(0)
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();
});

it('shows the requester the outside and the distance, never the inside or a coordinate', function () {
    $it = visitAssigned();
    fileVisit($it);
    $requester = $it['request']->requester()->firstOrFail();
    $page = "/enumerate/verifications/{$it['request']->reference}";

    // Filed but not yet confirmed: who and when, no findings, no photographs.
    $this->actingAs($requester, 'portal')->get($page)
        ->assertInertia(fn ($p) => $p->where('request.location.status', 'submitted')
            ->where('request.location.agentRef', 'FA-0231')
            ->where('request.location.checklist', null)
            ->where('request.location.photos', [])
            ->where('request.location.distanceM', null));

    app(ManageEnumerateVisits::class)->review($it['visit'], $it['supervisor'], true, null);

    $response = $this->actingAs($requester, 'portal')->get($page);
    $response->assertInertia(fn ($p) => $p->where('request.location.status', 'accepted')
        ->where('request.location.area', 'Garki 1, Abuja Municipal')
        ->where('request.location.atAddress', true)
        ->where('request.location.otherPhotos', 1)
        ->has('request.location.photos', 2)
        ->where('request.location.photos.0.kind', 'Storefront')
        ->where('request.location.photos.1.kind', 'Signage')
        ->has('request.location.checklist', 5)
        ->where('request.steps.3.state', 'done'));

    // Nothing on the page places a pin: neither the supervisor's nor the handset's.
    $props = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);
    expect($props)->not->toContain('9.042')
        ->and($props)->not->toContain('7.491')
        ->and($props)->not->toContain('visit_interior');
});

it('keeps a visit that is sent back, and opens the next one for the same officer', function () {
    $it = visitAssigned();
    fileVisit($it);
    $visits = app(ManageEnumerateVisits::class);

    expect(fn () => $visits->review($it['visit'], $it['supervisor'], false, 'Blurred'))
        ->toThrow(RuntimeException::class, 'Tell the officer what to redo');

    $visits->review($it['visit'], $it['supervisor'], false, 'The signage photo is blurred: retake it from the road.');

    $first = $it['visit']->refresh();
    $next = EnumerateVisit::query()->where('enumerate_request_id', $it['request']->id)->where('status', EnumerateVisit::ASSIGNED)->sole();

    expect($first->status)->toBe(EnumerateVisit::RETURNED)
        ->and($first->checklist)->toHaveCount(5)
        ->and($first->photos()->count())->toBe(3)
        ->and($next->agent_id)->toBe($it['officer']->id)
        ->and($next->ward_id)->toBe($first->ward_id)
        ->and($it['request']->refresh()->status)->toBe(RequestStatus::AgentAssigned)
        ->and(enumerateHeld($it['request']))->toBe(5_000_00);

    // The officer is told what to redo on the new visit's page.
    $this->actingAs($it['officer'])->get("/field/visits/{$next->id}")
        ->assertInertia(fn ($page) => $page->where('visit.redo', 'The signage photo is blurred: retake it from the road.'));
});

it('sends a confirmed Tier 3 on to monitoring and keeps its money held', function () {
    $it = visitAssigned(Tier::Activity, 30);
    fileVisit($it);

    app(ManageEnumerateVisits::class)->review($it['visit'], $it['supervisor'], true, null);

    expect($it['request']->refresh()->status)->toBe(RequestStatus::Monitoring)
        ->and($it['request']->completed_at)->toBeNull()
        ->and(enumerateHeld($it['request']))->toBe(12_000_00);
});

it('needs a storefront photograph and an arrival before a report', function () {
    $it = visitAssigned();
    $base = "/api/field/visits/{$it['visit']->id}";
    $answers = collect(EnumerateVisit::CHECKLIST)->map(fn () => ['passed' => true])->all();

    $this->actingAs($it['officer'])
        ->postJson("{$base}/report", ['report_uuid' => (string) Str::uuid7(), 'answers' => $answers])
        ->assertStatus(422)->assertJsonPath('message', 'Record your arrival before the report.');

    $this->postJson("{$base}/arrive", ['latitude' => 9.0421, 'longitude' => 7.4912])->assertOk();
    $this->post("{$base}/photos", ['client_uuid' => (string) Str::uuid7(), 'angle' => 'interior', 'photo' => UploadedFile::fake()->image('in.jpg')], ['Accept' => 'application/json'])->assertOk();

    $this->postJson("{$base}/report", ['report_uuid' => (string) Str::uuid7(), 'answers' => $answers])
        ->assertStatus(422)->assertJsonPath('message', 'Take at least one photograph of the storefront, or of the place the business should be.');
});

it('files a report once, however often the handset retries it', function () {
    $it = visitAssigned();
    fileVisit($it);
    $uuid = $it['visit']->refresh()->report_uuid;

    $this->actingAs($it['officer'])->postJson("/api/field/visits/{$it['visit']->id}/report", [
        'report_uuid' => $uuid,
        'answers' => collect(EnumerateVisit::CHECKLIST)->map(fn () => ['passed' => false])->all(),
    ])->assertOk();

    expect(collect($it['visit']->refresh()->checklist)->every(fn ($c) => $c['passed'] === true || $c['key'] === 'open_during_visit'))->toBeTrue();
});

it('shows a visit to its officer only', function () {
    $it = visitAssigned();
    $other = person(Role::Officer, 'Somebody Else');

    $this->actingAs($other)->get("/field/visits/{$it['visit']->id}")->assertNotFound();
    $this->postJson("/api/field/visits/{$it['visit']->id}/arrive", ['latitude' => 9.0421, 'longitude' => 7.4912])->assertNotFound();
});

it('refuses a pin outside Nigeria, somebody who is not an officer, and a request not waiting for one', function () {
    $request = awaitingAgent();
    $visits = app(ManageEnumerateVisits::class);
    $supervisor = person(Role::Supervisor);

    expect(fn () => $visits->assign($request, person(Role::Officer), $supervisor, 51.5, -0.12))
        ->toThrow(RuntimeException::class, 'not in Nigeria');
    expect(fn () => $visits->assign($request, person(Role::Supervisor), $supervisor, 9.0421, 7.4912))
        ->toThrow(RuntimeException::class, 'not an active field officer');

    $another = enumerateRequester('Another', '08038880008');
    enumerateFund($another, 5_000, 7002);
    $tier1 = enumerateCheck($another, Tier::Registry);
    expect(fn () => $visits->assign($tier1, person(Role::Officer), $supervisor, 9.0421, 7.4912))
        ->toThrow(RuntimeException::class, 'not waiting for an officer');
});

it('closes the first visit when reassigned before arrival, and refuses once the officer is there', function () {
    $it = visitAssigned();
    $visits = app(ManageEnumerateVisits::class);
    $second = person(Role::Officer, 'Ngozi Kalu');

    $moved = $visits->assign($it['request'], $second, $it['supervisor'], 9.0421, 7.4912);

    expect($it['visit']->refresh()->status)->toBe(EnumerateVisit::RETURNED)
        ->and($it['visit']->review_note)->toContain('Reassigned to Ngozi Kalu')
        ->and($moved->agent_id)->toBe($second->id);

    $visits->arrive($moved, $second, 7.4912, 9.0421, 5.0);

    expect(fn () => $visits->assign($it['request'], $it['officer'], $it['supervisor'], 9.0421, 7.4912))
        ->toThrow(RuntimeException::class, 'already at the premises');
});

it('pins and sends from the console, and reads the report there', function () {
    $request = awaitingAgent();
    $officer = person(Role::Officer, 'Musa Danjuma');
    $supervisor = person(Role::Supervisor);

    $this->actingAs($officer)->get('/console/enumerate-visits')->assertRedirect();

    $this->actingAs($supervisor)->get('/console/enumerate-visits')->assertOk()
        ->assertInertia(fn ($page) => $page->component('console/EnumerateVisits')
            ->where('requests.0.reference', $request->reference)
            ->where('requests.0.address', 'Plot 7, Ahmadu Bello Way, Garki, Abuja'));

    $this->post("/console/enumerate-visits/requests/{$request->reference}/assign", ['officer_id' => $officer->id, 'pin' => 'somewhere'])
        ->assertSessionHasErrors('pin');

    $this->post("/console/enumerate-visits/requests/{$request->reference}/assign", ['officer_id' => $officer->id, 'pin' => VISIT_PIN])
        ->assertSessionHasNoErrors();

    $visit = EnumerateVisit::query()->sole();
    fileVisit(['visit' => $visit, 'officer' => $officer]);

    $this->actingAs($supervisor)->get('/console/enumerate-visits')
        ->assertInertia(fn ($page) => $page->where('reviewing.id', $visit->id)
            ->has('reviewing.photos', 3)
            ->where('reviewing.pin', '9.042100, 7.491200'));

    $this->post("/console/enumerate-visits/{$visit->id}/decide", ['accept' => true])->assertRedirect('/console/enumerate-visits');

    expect($request->refresh()->status)->toBe(RequestStatus::Completed);
});
