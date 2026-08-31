<?php

declare(strict_types=1);

use App\Domain\Campaign\Actions\AcknowledgeCampaign;
use App\Domain\Campaign\Actions\AssembleCampaignDossier;
use App\Domain\Campaign\Actions\GenerateCampaignCode;
use App\Domain\Campaign\Actions\TransitionCampaign;
use App\Domain\Campaign\Enums\CampaignStatus;
use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignAgentAssignment;
use App\Domain\Campaign\Models\CampaignCommercial;
use App\Domain\Campaign\Models\CampaignStakeholder;
use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Campaign\Models\ClientUser;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see what, and what must never travel.
 *
 * Two things are load bearing here. One organisation must never reach another's
 * campaign, and a client must never receive a commercial field by any route at
 * all. The second is tested by walking the whole payload rather than by
 * checking the keys somebody remembered to check.
 */

/**
 * Every string that appears anywhere in a nested payload, keys included.
 *
 * @return array<string, string>
 */
function flatten(mixed $value, string $path = ''): array
{
    if (! is_array($value)) {
        return [$path => is_scalar($value) ? (string) $value : gettype($value)];
    }

    $flat = [];

    foreach ($value as $key => $child) {
        $flat += flatten($child, $path === '' ? (string) $key : "{$path}.{$key}");
    }

    return $flat;
}

it('shows a client only their own organisation, and only once it is theirs to see', function () {
    $mine = ClientOrganisation::factory()->create();
    $theirs = ClientOrganisation::factory()->create();

    $active = Campaign::factory()->forClient($mine)->active()->create();
    $draft = Campaign::factory()->forClient($mine)->create(['status' => CampaignStatus::Draft]);
    $other = Campaign::factory()->forClient($theirs)->active()->create();

    $visible = Campaign::query()->visibleToClient($mine->id)->pluck('id');

    expect($visible)->toContain($active->id)
        // A campaign still being written or priced is our working document.
        ->and($visible)->not->toContain($draft->id)
        ->and($visible)->not->toContain($other->id);
});

it('refuses a client admin reaching for another organisation\'s campaign', function () {
    $mine = ClientOrganisation::factory()->create();
    $theirs = ClientOrganisation::factory()->create();

    $client = ClientUser::factory()->create(['client_organisation_id' => $mine->id]);

    $ours = Campaign::factory()->forClient($mine)->active()->create();
    $notOurs = Campaign::factory()->forClient($theirs)->active()->create();

    expect(Gate::forUser($client)->allows('view', $ours))->toBeTrue()
        ->and(Gate::forUser($client)->allows('view', $notOurs))->toBeFalse();
});

it('keeps a draft away from the client whose campaign it will become', function () {
    $org = ClientOrganisation::factory()->create();
    $client = ClientUser::factory()->create(['client_organisation_id' => $org->id]);

    foreach ([CampaignStatus::Draft, CampaignStatus::PendingApproval] as $status) {
        $campaign = Campaign::factory()->forClient($org)->create(['status' => $status]);

        expect(Gate::forUser($client)->allows('view', $campaign))->toBeFalse();
    }
});

it('locks out a client whose organisation has been suspended', function () {
    $org = ClientOrganisation::factory()->suspended()->create();
    $client = ClientUser::factory()->create(['client_organisation_id' => $org->id]);
    $campaign = Campaign::factory()->forClient($org)->active()->create();

    // The account is fine; the relationship is not.
    expect($client->status)->toBe(ClientUser::STATUS_ACTIVE)
        ->and($client->canSignIn())->toBeFalse()
        ->and(Gate::forUser($client)->allows('view', $campaign))->toBeFalse();
});

it('never lets a commercial field reach a client, anywhere in the payload', function () {
    $org = ClientOrganisation::factory()->create();
    $campaign = Campaign::factory()->forClient($org)->active()->create();

    CampaignCommercial::factory()->create([
        'campaign_id' => $campaign->id,
        'contract_value' => 48_500_000,
        'internal_notes' => 'Margin is thin at this rate, hold the line on scope.',
    ]);

    $dossier = app(AssembleCampaignDossier::class)($campaign);
    $flat = flatten($dossier);

    // Walked whole rather than spot checked. A payload grows, and a test that
    // asserts the absence of the four keys somebody thought of on the day stops
    // being a test the first time a fifth is added.
    $keys = implode(' ', array_keys($flat));
    $values = implode(' ', array_values($flat));

    foreach (['contract', 'invoice', 'payment', 'internal_note', 'internalNote', 'margin'] as $forbidden) {
        expect(mb_strtolower($keys))->not->toContain($forbidden);
    }

    expect($values)->not->toContain('48500000')
        ->and($values)->not->toContain('Margin is thin')
        // And the relationship is not quietly loaded either, which is what
        // would put it one toArray() away from the page.
        ->and($campaign->relationLoaded('commercial'))->toBeFalse();
});

it('excludes a stakeholder we are keeping internal, at query level', function () {
    $campaign = Campaign::factory()->active()->create();

    CampaignStakeholder::factory()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Mining Cadastre Office',
    ]);
    CampaignStakeholder::factory()->internal()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Aide to the Honourable Minister',
    ]);

    $forClient = flatten(app(AssembleCampaignDossier::class)($campaign, includeInternal: false));
    $forStaff = flatten(app(AssembleCampaignDossier::class)($campaign, includeInternal: true));

    expect(implode(' ', $forClient))->toContain('Mining Cadastre Office')
        ->and(implode(' ', $forClient))->not->toContain('Aide to the Honourable Minister')
        ->and(implode(' ', $forStaff))->toContain('Aide to the Honourable Minister');
});

it('shows an officer only the campaigns they are actually out on', function () {
    $officer = person(Role::Officer);
    $mine = Campaign::factory()->active()->create();
    $notMine = Campaign::factory()->active()->create();

    CampaignAgentAssignment::factory()->create([
        'campaign_id' => $mine->id,
        'user_id' => $officer->id,
    ]);

    expect(Gate::forUser($officer)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($officer)->allows('view', $notMine))->toBeFalse()
        ->and(Campaign::query()->deployedTo($officer->id)->pluck('id')->all())->toBe([$mine->id]);
});

it('lets a supervisor read a campaign but never move or price one', function () {
    $supervisor = person(Role::Supervisor);
    $campaign = Campaign::factory()->active()->create();

    expect(Gate::forUser($supervisor)->allows('view', $campaign))->toBeTrue()
        ->and(Gate::forUser($supervisor)->allows('transition', $campaign))->toBeFalse()
        ->and(Gate::forUser($supervisor)->allows('viewCommercials', $campaign))->toBeFalse()
        ->and(Gate::forUser($supervisor)->allows('create', $campaign))->toBeFalse();
});

it('lets only an administrator see what a campaign is worth', function () {
    $campaign = Campaign::factory()->active()->create();

    expect(Gate::forUser(person(Role::Admin))->allows('viewCommercials', $campaign))->toBeTrue()
        ->and(Gate::forUser(person(Role::Officer))->allows('viewCommercials', $campaign))->toBeFalse();
});

it('refuses to activate a campaign nobody approved', function () {
    $admin = person(Role::Admin);
    $campaign = Campaign::factory()->create([
        'status' => CampaignStatus::Approved,
        'approved_at' => null,
        'starts_on' => now()->subDay(),
    ]);

    expect(fn () => app(TransitionCampaign::class)($campaign, CampaignStatus::Active, $admin))
        ->toThrow(RuntimeException::class, 'has to be approved');
});

it('refuses a transition the lifecycle does not allow', function () {
    $admin = person(Role::Admin);
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

    expect(fn () => app(TransitionCampaign::class)($campaign, CampaignStatus::Active, $admin))
        ->toThrow(RuntimeException::class, 'cannot become active');
});

it('holds back an early activation until somebody confirms the override', function () {
    $admin = person(Role::Admin);
    $campaign = Campaign::factory()->create([
        'status' => CampaignStatus::Approved,
        'approved_at' => now()->subWeek(),
        'starts_on' => now()->addWeeks(2),
    ]);

    expect(fn () => app(TransitionCampaign::class)($campaign, CampaignStatus::Active, $admin))
        ->toThrow(RuntimeException::class, 'not due to start');

    $activated = app(TransitionCampaign::class)(
        $campaign, CampaignStatus::Active, $admin, 'Client asked us to start early.', overrideStartDate: true,
    );

    expect($activated->status)->toBe(CampaignStatus::Active);
});

it('writes every move to the log, with what it moved from', function () {
    $admin = person(Role::Admin);
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);
    $transition = app(TransitionCampaign::class);

    $transition($campaign, CampaignStatus::PendingApproval, $admin);
    $transition($campaign, CampaignStatus::Approved, $admin, 'Terms agreed.');

    expect($campaign->refresh()->approved_by)->toBe($admin->id)
        ->and($campaign->approved_at)->not->toBeNull();

    $events = VerificationEvent::query()
        ->where('subject_type', $campaign->getMorphClass())
        ->where('subject_id', $campaign->id)
        ->pluck('event');

    expect($events)->toContain('campaign.pending_approval')
        ->and($events)->toContain('campaign.approved');
});

it('shows the brief once, and again only when it is materially revised', function () {
    $campaign = Campaign::factory()->active()->create();
    $client = ClientUser::factory()->create();
    $acknowledge = app(AcknowledgeCampaign::class);

    expect($acknowledge->outstandingFor($campaign, $client))->toBeTrue();

    $acknowledge->record($campaign, $client);

    expect($acknowledge->outstandingFor($campaign->refresh(), $client))->toBeFalse();

    $acknowledge->requireReacknowledgement($campaign);

    expect($acknowledge->outstandingFor($campaign->refresh(), $client))->toBeTrue()
        // The row is kept, so who read which version stays answerable.
        ->and($campaign->acknowledgements()->count())->toBe(1);
});

it('numbers a client\'s campaigns in sequence within the year', function () {
    $client = ClientOrganisation::factory()->create(['short_code' => 'NRS']);
    $generate = app(GenerateCampaignCode::class);

    $first = $generate($client, 'Mining companies', 2026);
    Campaign::factory()->forClient($client)->create(['code' => $first]);

    $second = $generate($client, 'Mining companies', 2026);

    expect($first)->toBe('NRS-MIN-2026-01')
        ->and($second)->toBe('NRS-MIN-2026-02')
        // A different subject starts its own series.
        ->and($generate($client, 'Hair extension traders', 2026))->toBe('NRS-HAI-2026-01');
});

it('counts days remaining in Lagos, not wherever the server is', function () {
    // Late enough in the day that a UTC calculation would land on the day
    // before. Lagos is an hour ahead, and the register is read in Lagos.
    $this->travelTo(new DateTimeImmutable('2026-08-30 23:30:00', new DateTimeZone('Africa/Lagos')));

    $campaign = Campaign::factory()->active()->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-09-30',
    ]);

    expect(config('app.timezone'))->toBe('Africa/Lagos')
        ->and($campaign->daysRemaining())->toBe(31);

    $this->travelBack();
});

/*
|--------------------------------------------------------------------------
| Over HTTP
|--------------------------------------------------------------------------
|
| The tests above prove the policy and the assembler. These prove the wiring:
| that the routes actually consult them, and that a real response to a real
| client session carries nothing it should not.
|
*/

it('answers 403 when a client reaches for another organisation\'s campaign', function () {
    $mine = ClientOrganisation::factory()->create();
    $theirs = ClientOrganisation::factory()->create();

    $client = ClientUser::factory()->create(['client_organisation_id' => $mine->id]);
    $notOurs = Campaign::factory()->forClient($theirs)->active()->create();

    $this->actingAs($client, 'client')
        ->get("/client/campaigns/{$notOurs->id}")
        ->assertForbidden();
});

it('keeps a client out of every in-house view', function (string $path) {
    $client = ClientUser::factory()->create();

    /*
     * A client session cannot satisfy the staff middleware, because they are
     * different guards rather than different roles.
     *
     * Refused, and the shape of the refusal depends on how they arrived. In a
     * browser the default guard is `web`, so `auth` sees nobody and sends them
     * to the staff login. Here, acting as a client makes `client` the default
     * guard, so `auth` is satisfied and `supervises` refuses the account it
     * finds. What matters is that neither road ends at the page.
     */
    $response = $this->actingAs($client, 'client')->get($path);

    expect($response->status())->toBeIn([302, 403]);
})->with(['/console/review', '/admin/campaigns', '/admin/audit']);

it('keeps staff out of the client surface', function () {
    $admin = person(Role::Admin);

    $this->actingAs($admin)->get('/client')->assertRedirect('/client/sign-in');
});

it('sends no commercial field down the wire to a client', function () {
    $org = ClientOrganisation::factory()->create();
    $client = ClientUser::factory()->create(['client_organisation_id' => $org->id]);
    $campaign = Campaign::factory()->forClient($org)->active()->create();

    CampaignCommercial::factory()->create([
        'campaign_id' => $campaign->id,
        'contract_value' => 48_500_000,
        'internal_notes' => 'Margin is thin at this rate.',
    ]);

    // The whole serialised response, not a chosen slice of it. Inertia puts the
    // page props into the document, so anything the controller loaded is here
    // whether the component reads it or not.
    $body = $this->actingAs($client, 'client')
        ->get("/client/campaigns/{$campaign->id}")
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('48500000')
        ->and($body)->not->toContain('48,500,000')
        ->and($body)->not->toContain('Margin is thin')
        ->and($body)->not->toContain('contract_value')
        ->and($body)->not->toContain('contractValue')
        ->and($body)->not->toContain('internal_notes')
        ->and($body)->not->toContain('internalNotes')
        ->and($body)->not->toContain('payment_status');
});

it('gives an administrator the commercials the client never sees', function () {
    $admin = person(Role::Admin);
    $campaign = Campaign::factory()->active()->create();

    CampaignCommercial::factory()->create([
        'campaign_id' => $campaign->id,
        'contract_value' => 48_500_000,
    ]);

    $this->actingAs($admin)
        ->get("/admin/campaigns/{$campaign->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('commercial.contractValue', '48500000.00'));
});

it('records an acknowledgement over HTTP and stops asking', function () {
    $org = ClientOrganisation::factory()->create();
    $client = ClientUser::factory()->create(['client_organisation_id' => $org->id]);
    $campaign = Campaign::factory()->forClient($org)->active()->create();

    $this->actingAs($client, 'client')
        ->get("/client/campaigns/{$campaign->id}")
        ->assertInertia(fn ($page) => $page->where('mustAcknowledge', true));

    $this->actingAs($client, 'client')
        ->post("/client/campaigns/{$campaign->id}/acknowledge")
        ->assertRedirect();

    $this->actingAs($client, 'client')
        ->get("/client/campaigns/{$campaign->id}")
        ->assertInertia(fn ($page) => $page->where('mustAcknowledge', false));
});

it('shows the dashboard an empty state rather than a broken widget', function () {
    $org = ClientOrganisation::factory()->create();
    $client = ClientUser::factory()->create(['client_organisation_id' => $org->id]);

    Campaign::factory()->forClient($org)->completed()->create();

    $this->actingAs($client, 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('campaign', null)->where('active', []));
});
