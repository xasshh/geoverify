<?php

declare(strict_types=1);

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\CampaignCommercial;
use App\Domain\Campaign\Models\ClientOrganisation;
use App\Domain\Enumerate\Actions\EnumerateContext;
use App\Domain\Enumerate\Actions\FundRequesterWallet;
use App\Domain\Enumerate\Actions\ImportBulkVerification;
use App\Domain\Enumerate\Actions\ManageOrganisations;
use App\Domain\Enumerate\Actions\ManageProjects;
use App\Domain\Enumerate\Actions\ManageRequesterWallet;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateBatch;
use App\Domain\Enumerate\Models\EnumerateBatchRow;
use App\Domain\Enumerate\Models\EnumerateMember;
use App\Domain\Enumerate\Models\EnumerateOrganisation;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Ledger\Actions\ReadLedgerBalances;
use App\Domain\Party\Models\PortalAccount;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Enumerate E5: organisations.
 *
 * Worth proving: an organisation's money and a member's own never mix, and
 * the switcher is the only thing that decides which is spent; each role does
 * what it says and no more; an organisation always keeps an admin; bulk and
 * projects wait for approval; a bulk batch is paid in full or not at all; and
 * a project shows the campaign's progress but never its commercials.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    config()->set('services.paystack.secret', 'sk_test_marketplace');
    config()->set('services.registry.driver', 'fake');
});

/** @return array{organisation: EnumerateOrganisation, admin: PortalAccount, seat: EnumerateMember} */
function organisation(bool $approved = true): array
{
    $admin = enumerateRequester('Tunde Adebayo', '08031234501');
    $organisation = app(ManageOrganisations::class)->open($admin, 'Acme Logistics Ltd', 'RC 998877', 'accounts@acme.test');

    if ($approved) {
        app(ManageOrganisations::class)->decide($organisation, person(Role::Admin), true, null);
    }

    return [
        'organisation' => $organisation->refresh(),
        'admin' => $admin,
        'seat' => EnumerateMember::query()->where('organisation_id', $organisation->id)->sole(),
    ];
}

/** Credit an organisation wallet the only way money arrives: a funding and the signed webhook. */
function fundOrganisation(EnumerateOrganisation $organisation, PortalAccount $by, int $naira, int $chargeId = 8001): void
{
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/org']])]);
    $wallet = app(ManageRequesterWallet::class)->walletForOrganisation($organisation->id);
    $funding = app(FundRequesterWallet::class)($by, $naira * 100, 'bank_transfer', 'https://example.test/return', $wallet)['funding'];

    expect(deliver(signedEvent('charge.success', [
        'id' => $chargeId, 'reference' => $funding->reference, 'amount' => $naira * 100, 'currency' => 'NGN', 'status' => 'success',
    ])))->toBe(200);
}

function organisationNaira(EnumerateOrganisation $organisation): int
{
    $wallets = app(ManageRequesterWallet::class);

    return intdiv($wallets->balanceMinor($wallets->walletForOrganisation($organisation->id)), 100);
}

/**
 * Invite a phone to a role and have its owner accept.
 *
 * @param  array{organisation: EnumerateOrganisation, admin: PortalAccount, seat: EnumerateMember}  $org
 */
function seatFor(array $org, string $name, string $phone, string $role): PortalAccount
{
    $account = enumerateRequester($name, $phone);
    $seat = app(ManageOrganisations::class)->invite($org['seat'], $phone, $role);
    app(ManageOrganisations::class)->accept($seat, $account);

    return $account;
}

const BULK_CSV = <<<'CSV'
business name,RC/BN number,TIN,address
Kora Build Supplies Limited,RC 1482093,23984417-0001,"Plot 7, Ahmadu Bello Way, Garki"
Sahel Solar Systems Limited,1739021,,Wuse 2
,RC 1620554,,
Amaka Fresh Foods Limited,RC 1620554,,Enugu
Kora again,RC 1482093,,
CSV;

it('opens an organisation with its opener as admin, and a wallet of its own', function () {
    $org = organisation(approved: false);

    expect($org['organisation']->status)->toBe(EnumerateOrganisation::PENDING)
        ->and($org['seat']->role)->toBe('admin')
        ->and($org['seat']->accepted_at)->not->toBeNull()
        ->and(organisationNaira($org['organisation']))->toBe(0);

    $this->actingAs($org['admin'], 'portal')
        ->post('/enumerate/switch', ['organisation' => $org['organisation']->id])
        ->assertRedirect('/enumerate');

    $this->get('/enumerate')->assertRedirect('/enumerate/organisation');
    $this->get('/enumerate/organisation')->assertOk()
        ->assertInertia(fn ($page) => $page->component('enumerate/Overview')
            ->where('frame.organisation.name', 'Acme Logistics Ltd')
            ->where('frame.organisation.status', 'pending')
            ->where('frame.organisation.can.bulk', false)
            ->where('frame.organisation.can.request', true));
});

it('spends the organisation’s money when acting for it, and the person’s own otherwise', function () {
    $org = organisation();
    fundOrganisation($org['organisation'], $org['admin'], 10_000);
    enumerateFund($org['admin'], 3_000, 7301);

    $this->actingAs($org['admin'], 'portal')->post('/enumerate/switch', ['organisation' => $org['organisation']->id]);
    $this->post('/enumerate/verify', ['name' => 'SAHEL SOLAR SYSTEMS LIMITED', 'rc_number' => '1739021', 'company_type' => 'COMPANY', 'tier' => 2]);

    $bought = EnumerateRequest::query()->sole();
    expect($bought->organisation_id)->toBe($org['organisation']->id)
        ->and(organisationNaira($org['organisation']))->toBe(5_000)
        ->and(enumerateWalletNaira($org['admin']))->toBe(3_000);

    // Back as themselves: not their check, and their wallet in the header.
    $this->post('/enumerate/switch', ['organisation' => null]);
    $this->get('/enumerate/verifications')->assertInertia(fn ($page) => $page->has('requests', 0)->where('frame.walletMinor', 3_000_00));
    $this->get("/enumerate/verifications/{$bought->reference}")->assertNotFound();
});

it('lets each role do what it says and no more', function () {
    $org = organisation();
    fundOrganisation($org['organisation'], $org['admin'], 10_000);
    $viewer = seatFor($org, 'Ruth Eze', '08031234504', 'viewer');
    $requester = seatFor($org, 'Ibrahim Bala', '08031234503', 'requester');

    $this->actingAs($viewer, 'portal')->post('/enumerate/switch', ['organisation' => $org['organisation']->id]);
    $this->post('/enumerate/verify', ['name' => 'X LIMITED', 'rc_number' => '1739021', 'company_type' => 'COMPANY', 'tier' => 1])
        ->assertSessionHasErrors(['tier' => 'Your seat can view this organisation’s checks but not buy them.']);

    $this->actingAs($requester, 'portal')->post('/enumerate/switch', ['organisation' => $org['organisation']->id]);
    $this->post('/enumerate/wallet/fund', ['amount' => 5000, 'channel' => 'card'])
        ->assertSessionHasErrors(['amount' => 'Only an admin or a project lead funds the organisation wallet.']);

    $requesterSeat = EnumerateMember::query()->where('portal_account_id', $requester->id)->sole();
    expect(fn () => app(ManageOrganisations::class)->invite($requesterSeat, '08031234599', 'admin'))
        ->toThrow(RuntimeException::class, 'Only an admin');
});

it('always keeps an admin', function () {
    $org = organisation();
    $organisations = app(ManageOrganisations::class);

    expect(fn () => $organisations->changeRole($org['seat'], $org['seat'], 'viewer'))->toThrow(RuntimeException::class, 'always keeps one admin');
    expect(fn () => $organisations->revoke($org['seat'], $org['seat']))->toThrow(RuntimeException::class, 'always keeps one admin');

    $lead = seatFor($org, 'Ngozi Kalu', '08031234502', 'project_lead');
    $leadSeat = EnumerateMember::query()->where('portal_account_id', $lead->id)->sole();
    $organisations->changeRole($org['seat'], $leadSeat, 'admin');
    $organisations->changeRole($leadSeat->refresh(), $org['seat'], 'viewer');

    expect($org['seat']->refresh()->role)->toBe('viewer');
});

it('waits for the invited person, finds them by phone, and forgets a revoked seat at once', function () {
    $org = organisation();
    $seat = app(ManageOrganisations::class)->invite($org['seat'], '0803 123 4505', 'requester');
    $account = enumerateRequester('Folake Ogun', '08031234505');

    $this->actingAs($account, 'portal')->get('/enumerate')
        ->assertInertia(fn ($page) => $page->where('frame.invitations.0.organisation', 'Acme Logistics Ltd'));

    $this->post("/enumerate/invitations/{$seat->id}/accept")->assertRedirect('/enumerate/organisation');
    expect(app(EnumerateContext::class)->organisations($account))->toHaveCount(1);

    // Somebody else cannot take it.
    expect(fn () => app(ManageOrganisations::class)->accept($seat->refresh(), enumerateRequester('Intruder', '08031234506')))
        ->toThrow(RuntimeException::class, 'not for you');

    app(ManageOrganisations::class)->revoke($org['seat'], $seat->refresh());
    $this->get('/enumerate/organisation')->assertRedirect('/enumerate');
});

it('opens bulk and projects only once an administrator approves', function () {
    $org = organisation(approved: false);

    expect(fn () => app(ImportBulkVerification::class)($org['seat'], $org['admin'], BULK_CSV, Tier::Registry, null))
        ->toThrow(RuntimeException::class, 'once we have approved');

    expect(fn () => app(ManageOrganisations::class)->decide($org['organisation'], person(Role::Supervisor), true, null))
        ->toThrow(RuntimeException::class, 'Only an administrator');

    $this->actingAs(person(Role::Admin))->post("/admin/enumerate-organisations/{$org['organisation']->id}/decide", ['approve' => true])
        ->assertSessionHasNoErrors();

    expect($org['organisation']->refresh()->approved())->toBeTrue();
});

it('checks a CSV line by line, and pays for exactly the lines it can check', function () {
    $org = organisation();
    fundOrganisation($org['organisation'], $org['admin'], 10_000);

    $batch = app(ImportBulkVerification::class)($org['seat'], $org['admin'], BULK_CSV, Tier::Registry, null);

    expect([$batch->rows_total, $batch->rows_placed, $batch->rows_refused, $batch->total_minor])->toBe([5, 3, 2, 4_500_00])
        ->and(organisationNaira($org['organisation']))->toBe(5_500)
        ->and(EnumerateRequest::query()->where('enumerate_batch_id', $batch->id)->count())->toBe(3)
        ->and(EnumerateBatchRow::query()->where('line', 4)->value('reason'))->toBe('No business name.')
        ->and(EnumerateBatchRow::query()->where('line', 6)->value('reason'))->toBe('Already on line 2.')
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();

    // Each placed line is an ordinary request, read by the same lookups.
    expect(EnumerateRequest::query()->where('rc_number', '1482093')->sole()->latestChecks()['cac']->outcome)->toBe('matched');
});

it('places none of a batch the wallet cannot cover', function () {
    $org = organisation();
    fundOrganisation($org['organisation'], $org['admin'], 4_000);

    expect(fn () => app(ImportBulkVerification::class)($org['seat'], $org['admin'], BULK_CSV, Tier::Registry, null))
        ->toThrow(RuntimeException::class, 'the wallet holds ₦4,000');

    expect(EnumerateRequest::query()->count())->toBe(0)
        ->and(organisationNaira($org['organisation']))->toBe(4_000);

    expect(fn () => app(ImportBulkVerification::class)($org['seat'], $org['admin'], "name;rc\nA;1", Tier::Registry, null))
        ->toThrow(RuntimeException::class, 'The first line must name the columns');
});

it('takes a bulk CSV through the page', function () {
    $org = organisation();
    fundOrganisation($org['organisation'], $org['admin'], 10_000);

    $this->actingAs($org['admin'], 'portal')->post('/enumerate/switch', ['organisation' => $org['organisation']->id]);
    $response = $this->post('/enumerate/organisation/bulk', [
        'file' => UploadedFile::fake()->createWithContent('suppliers.csv', BULK_CSV),
        'tier' => 1,
    ]);

    $batch = EnumerateBatch::query()->sole();
    $response->assertRedirect("/enumerate/organisation/bulk/{$batch->reference}");

    $this->get("/enumerate/organisation/bulk/{$batch->reference}")
        ->assertInertia(fn ($page) => $page->component('enumerate/Batch')->has('rows', 5)->where('rows.2.outcome', 'refused'));
});

it('takes a project from request to live, and shows the campaign without its commercials', function () {
    $org = organisation();
    $lead = seatFor($org, 'Ngozi Kalu', '08031234502', 'project_lead');
    $leadSeat = EnumerateMember::query()->where('portal_account_id', $lead->id)->sole();
    $requester = seatFor($org, 'Ibrahim Bala', '08031234503', 'requester');
    $projects = app(ManageProjects::class);

    $brief = [
        'name' => 'Vendor onboarding survey, Lagos',
        'subject' => 'Vendors',
        'area' => 'Kosofe and Ikeja, Lagos',
        'target_records' => 1200,
        'fields' => [['label' => 'Business name', 'type' => 'text'], ['label' => 'Has loading bay', 'type' => 'yes_no'], ['label' => '', 'type' => 'text']],
    ];

    expect(fn () => $projects->request(EnumerateMember::query()->where('portal_account_id', $requester->id)->sole(), $requester, $brief))
        ->toThrow(RuntimeException::class, 'Only an admin or a project lead');

    $project = $projects->request($leadSeat, $lead, $brief);

    expect($project->reference)->toMatch('/^PRJ-\d{8}-[A-Z2-9]{5}$/')
        ->and($project->fields)->toHaveCount(2)
        ->and($projects->progress($project))->toBeNull();

    expect(fn () => $projects->move($project, person(Role::Admin), 'live', null))
        ->toThrow(RuntimeException::class, 'Link the campaign');

    $campaign = Campaign::factory()->forClient(ClientOrganisation::factory()->create())->active()->create(['target_record_count' => 1200]);
    CampaignCommercial::factory()->create(['campaign_id' => $campaign->id, 'contract_value' => 12_345_678, 'internal_notes' => 'Margin is thin on this one']);

    $this->actingAs(person(Role::Admin))->post("/admin/enumerate-projects/{$project->id}", ['status' => 'live', 'campaign_id' => $campaign->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($lead, 'portal')->post('/enumerate/switch', ['organisation' => $org['organisation']->id]);
    $response = $this->get("/enumerate/organisation/projects/{$project->reference}");

    $response->assertInertia(fn ($page) => $page->component('enumerate/Project')
        ->where('project.status', 'live')
        ->where('project.progress.campaignCode', $campaign->code)
        ->where('project.progress.target', 1200));

    $props = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);
    expect($props)->not->toContain('12345678')
        ->and($props)->not->toContain('Margin is thin');
});

it('shows an organisation’s pages only to its members', function () {
    $org = organisation();
    $project = app(ManageProjects::class)->request($org['seat'], $org['admin'], [
        'name' => 'Borehole census', 'subject' => 'Boreholes', 'area' => 'Bwari, FCT',
        'fields' => [['label' => 'Working', 'type' => 'yes_no']],
    ]);
    $stranger = enumerateRequester('Stranger', '08031234507');
    app(ManageOrganisations::class)->open($stranger, 'Other Org Ltd', null, null);

    $this->actingAs($stranger, 'portal')->post('/enumerate/switch', ['organisation' => $org['organisation']->id]);
    $this->get('/enumerate/organisation')->assertRedirect('/enumerate');

    $this->actingAs(person(Role::Supervisor))->get('/admin/enumerate-organisations')->assertForbidden();
    $this->actingAs(person(Role::Admin))->get('/admin/enumerate-organisations')
        ->assertInertia(fn ($page) => $page->component('admin/EnumerateOrganisations')->has('organisations', 2)->where('projects.0.reference', $project->reference));
});
