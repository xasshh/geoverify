<?php

declare(strict_types=1);

use App\Domain\Enumerate\Actions\DecideDeskCheck;
use App\Domain\Enumerate\Actions\FundRequesterWallet;
use App\Domain\Enumerate\Actions\ManageRequesterWallet;
use App\Domain\Enumerate\Actions\PlaceEnumerateRequest;
use App\Domain\Enumerate\Actions\RunRegistryChecks;
use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\RegistryCheck;
use App\Domain\Enumerate\Models\WalletFunding;
use App\Domain\Enumerate\Registry\DojahRegistry;
use App\Domain\Enumerate\Registry\FakeRegistry;
use App\Domain\Enumerate\Registry\PremblyRegistry;
use App\Domain\Enumerate\Registry\RegistryUnavailable;
use App\Domain\Ledger\Actions\ReadLedgerBalances;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Party\Actions\ManagePortalCredentials;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Models\PortalAccount;
use App\Enums\Role;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Enumerate E1: a wallet, a paid request, the registry lookups and the desk check.
 *
 * As with the marketplace, what is worth proving is where the money is at each
 * step: the wallet grows only on the signed webhook, a request is paid from
 * credit already held, and a desk check that ends the job earns exactly the
 * part of the price that was worked for.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    config()->set('services.paystack.secret', 'sk_test_marketplace');
    config()->set('services.registry.driver', 'fake');
});

function enumerateRequester(string $name = 'Adaeze Nwosu', string $phone = '08036660006'): PortalAccount
{
    return app(ManagePortalCredentials::class)->registerBuyer((new NormalisePhone)($phone), $name, null);
}

/** Tops the wallet up the only way it can be: a started funding, then the signed webhook. */
function enumerateFund(PortalAccount $account, int $naira, int $chargeId = 7001): WalletFunding
{
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']])]);

    $funding = app(FundRequesterWallet::class)($account, $naira * 100, 'card', 'https://example.test/return')['funding'];

    expect(deliver(signedEvent('charge.success', [
        'id' => $chargeId,
        'reference' => $funding->reference,
        'amount' => $naira * 100,
        'currency' => 'NGN',
        'status' => 'success',
        'paid_at' => now()->toIso8601String(),
    ])))->toBe(200);

    return $funding->refresh();
}

function enumerateWalletNaira(PortalAccount $account): int
{
    $wallets = app(ManageRequesterWallet::class);

    return intdiv($wallets->balanceMinor($wallets->walletFor($account)), 100);
}

/** @param  array{name: string, rcNumber: string, companyType: string}|null  $subject */
function enumerateCheck(PortalAccount $account, Tier $tier = Tier::Registry, ?int $days = null, ?array $subject = null): EnumerateRequest
{
    return app(PlaceEnumerateRequest::class)(
        $account,
        $subject ?? ['name' => 'KORA BUILD SUPPLIES LIMITED', 'rcNumber' => '1482093', 'companyType' => 'COMPANY'],
        $tier,
        $days,
    )->refresh();
}

function enumerateHeld(EnumerateRequest $request): int
{
    return -(int) DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
        ->where('ledger_accounts.code', LedgerAccount::CUSTOMER_FUNDS_HELD)
        ->where('ledger_entries.enumerate_request_id', $request->id)
        ->sum('amount_minor');
}

it('grows the wallet only on the signed webhook, once, and by exactly what arrived', function () {
    $account = enumerateRequester();
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']])]);

    $started = app(FundRequesterWallet::class)($account, 5_000_00, 'bank_transfer', 'https://example.test/return');

    expect($started['url'])->toBe('https://checkout.paystack.com/abc')
        ->and($started['funding']->reference)->toMatch('/^FND-\d{8}-[A-Z2-9]{4}$/')
        ->and(enumerateWalletNaira($account))->toBe(0);

    // Coming back from the provider's page records nothing.
    $this->actingAs($account, 'portal')->get('/enumerate/wallet/return')->assertRedirect('/enumerate/wallet');
    expect(enumerateWalletNaira($account))->toBe(0);

    $event = signedEvent('charge.success', [
        'id' => 7101, 'reference' => $started['funding']->reference, 'amount' => 5_000_00,
        'currency' => 'NGN', 'status' => 'success', 'paid_at' => now()->toIso8601String(),
    ]);

    expect(deliver($event))->toBe(200)
        ->and(deliver($event))->toBe(200)
        ->and(enumerateWalletNaira($account))->toBe(5_000)
        ->and($started['funding']->refresh()->status)->toBe(WalletFunding::PAID)
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();
});

it('does not credit a top-up that arrived at a different amount', function () {
    $account = enumerateRequester();
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']])]);
    $funding = app(FundRequesterWallet::class)($account, 5_000_00, 'card', 'https://example.test/return')['funding'];

    deliver(signedEvent('charge.success', [
        'id' => 7102, 'reference' => $funding->reference, 'amount' => 500_00,
        'currency' => 'NGN', 'status' => 'success',
    ]));

    expect(enumerateWalletNaira($account))->toBe(0)
        ->and($funding->refresh()->status)->toBe(WalletFunding::PENDING);
});

it('refuses a top-up below one Tier 1 check', function () {
    app(FundRequesterWallet::class)(enumerateRequester(), 1_000_00, 'card', 'https://example.test/return');
})->throws(RuntimeException::class, 'The smallest top-up is ₦1,500.');

it('refuses a check the wallet cannot pay for, and takes nothing', function () {
    $account = enumerateRequester();
    enumerateFund($account, 3_000);

    expect(fn () => enumerateCheck($account, Tier::Location))
        ->toThrow(RuntimeException::class, 'Your wallet does not hold enough');

    expect(enumerateWalletNaira($account))->toBe(3_000)
        ->and(EnumerateRequest::query()->count())->toBe(0);
});

it('runs a Tier 1 check from payment to a passed result, earning the fee', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);

    $request = enumerateCheck($account);

    expect($request->reference)->toMatch('/^VRF-\d{8}-[A-Z2-9]{6}$/')
        ->and($request->status)->toBe(RequestStatus::RegistryCheck)
        ->and(enumerateWalletNaira($account))->toBe(3_500)
        ->and(enumerateHeld($request))->toBe(1_500_00);

    $checks = $request->latestChecks();
    expect($checks['cac']->outcome)->toBe(RegistryCheck::MATCHED)
        ->and($checks['cac']->facts['incorporatedOn'])->toBe('2016-03-14')
        ->and($checks['cac']->facts['directors'])->toHaveCount(2)
        ->and($checks['tin']->outcome)->toBe(RegistryCheck::MATCHED)
        ->and($checks['tin']->facts['tin'])->toBe('23984417-0001')
        // The address the officer is sent to is the one CAC holds.
        ->and($request->refresh()->registered_address)->toBe('Plot 7, Ahmadu Bello Way, Garki, Abuja');

    $decided = app(DecideDeskCheck::class)($request, person(Role::Supervisor), true, null);
    $ledger = app(ReadLedgerBalances::class)();

    expect($decided->status)->toBe(RequestStatus::Passed)
        ->and($decided->completed_at)->not->toBeNull()
        ->and(enumerateHeld($request))->toBe(0)
        ->and(enumerateWalletNaira($account))->toBe(3_500)
        ->and($ledger['balances'])->toBeTrue();
});

it('finds a TIN held under another name, and a failed Tier 1 still earns its fee', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);

    $request = enumerateCheck($account, subject: ['name' => 'ACME VENTURES NIG. LTD', 'rcNumber' => 'RC 1101187', 'companyType' => 'COMPANY']);
    $checks = $request->latestChecks();

    // "NIG. LTD" is how people write NIGERIA LIMITED: the name still matches.
    expect($checks['cac']->outcome)->toBe(RegistryCheck::MATCHED)
        ->and($checks['tin']->outcome)->toBe(RegistryCheck::MISMATCHED)
        ->and($checks['tin']->note)->toBe('TIN does not match CAC name.');

    expect(fn () => app(DecideDeskCheck::class)($request, person(Role::Supervisor), false, null))
        ->toThrow(RuntimeException::class, 'Say why it failed');

    $failed = app(DecideDeskCheck::class)($request, person(Role::Supervisor), false, 'TIN does not match CAC name');

    expect($failed->status)->toBe(RequestStatus::Failed)
        ->and($failed->registry_reason)->toBe('TIN does not match CAC name')
        ->and(enumerateWalletNaira($account))->toBe(3_500)
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();
});

it('says so when CAC holds the number for somebody else, or the company is struck off', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);

    $wrongName = enumerateCheck($account, subject: ['name' => 'SOMEBODY ELSE LIMITED', 'rcNumber' => '1482093', 'companyType' => 'COMPANY']);
    $struckOff = enumerateCheck($account, subject: ['name' => 'Old Harbour Trading Company Ltd', 'rcNumber' => '0412288', 'companyType' => 'COMPANY']);

    expect($wrongName->latestChecks()['cac']->outcome)->toBe(RegistryCheck::MISMATCHED)
        ->and($wrongName->latestChecks()['cac']->note)->toBe('CAC holds that number for KORA BUILD SUPPLIES LIMITED.')
        ->and($struckOff->latestChecks()['cac']->note)->toBe('CAC status is Struck off.');
});

it('returns what no officer was sent for when a Tier 3 fails at the desk', function () {
    $account = enumerateRequester();
    enumerateFund($account, 20_000);

    $request = enumerateCheck($account, Tier::Activity, 30);

    expect($request->price_minor)->toBe(12_000_00)
        ->and($request->registry_fee_minor)->toBe(1_500_00)
        ->and(enumerateWalletNaira($account))->toBe(8_000);

    app(DecideDeskCheck::class)($request, person(Role::Supervisor), false, 'CAC status is inactive');

    expect(enumerateWalletNaira($account))->toBe(18_500)
        ->and(enumerateHeld($request))->toBe(0)
        ->and(app(ReadLedgerBalances::class)()['balances'])->toBeTrue();
});

it('holds a passed Tier 2 for its officer and earns nothing yet', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);

    $request = enumerateCheck($account, Tier::Location);
    $passed = app(DecideDeskCheck::class)($request, person(Role::Supervisor), true, null);

    expect($passed->status)->toBe(RequestStatus::AwaitingAgent)
        ->and($passed->completed_at)->toBeNull()
        ->and(enumerateHeld($request))->toBe(5_000_00)
        ->and(enumerateWalletNaira($account))->toBe(0);
});

it('asks for a monitoring period only on Tier 3', function () {
    $account = enumerateRequester();
    enumerateFund($account, 20_000);

    expect(fn () => enumerateCheck($account, Tier::Activity, 10))->toThrow(RuntimeException::class, 'Choose 7, 14 or 30 days');
    expect(enumerateCheck($account, Tier::Location, 30)->monitoring_days)->toBeNull();
    expect(enumerateCheck($account, Tier::Activity, 7)->price_minor)->toBe(6_000_00);
});

it('leaves the request with the supervisor when the provider does not answer', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);

    $request = enumerateCheck($account, subject: ['name' => 'OUTAGE LIMITED', 'rcNumber' => FakeRegistry::UNAVAILABLE_RC, 'companyType' => 'COMPANY']);

    expect($request->status)->toBe(RequestStatus::RegistryCheck)
        ->and($request->latestChecks()['cac']->outcome)->toBe(RegistryCheck::UNAVAILABLE)
        ->and($request->latestChecks()['tin']->outcome)->toBe(RegistryCheck::UNAVAILABLE);

    // A second run appends; nothing is overwritten.
    app(RunRegistryChecks::class)($request);
    expect($request->checks()->count())->toBe(4);
});

it('refuses a desk decision from anyone but a supervisor, and a second one', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);
    $request = enumerateCheck($account);

    expect(fn () => app(DecideDeskCheck::class)($request, person(Role::Officer), true, null))
        ->toThrow(RuntimeException::class, 'Only a supervisor');

    app(DecideDeskCheck::class)($request, person(Role::Supervisor), true, null);

    expect(fn () => app(DecideDeskCheck::class)($request, person(Role::Supervisor), false, 'Changed my mind'))
        ->toThrow(RuntimeException::class, 'already been decided');
});

it('decides a desk check from the console, and keeps officers out of it', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);
    $request = enumerateCheck($account);

    $this->actingAs(person(Role::Officer))->get('/console/desk-checks')->assertRedirect();

    $this->actingAs(person(Role::Supervisor))
        ->get('/console/desk-checks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('console/DeskChecks')
            ->where('selected.reference', $request->reference)
            ->where('selected.checks.0.outcome', RegistryCheck::MATCHED));

    $this->post("/console/desk-checks/{$request->reference}", ['passed' => true])
        ->assertRedirect('/console/desk-checks');

    expect($request->refresh()->status)->toBe(RequestStatus::Passed);
});

it('shows a request to the wallet that paid for it and to nobody else', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);
    $request = enumerateCheck($account);

    $this->actingAs($account, 'portal')
        ->get("/enumerate/verifications/{$request->reference}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('enumerate/Request')
            ->where('request.reference', $request->reference)
            ->where('request.registry.cac.outcome', RegistryCheck::MATCHED)
            ->where('request.steps.0.state', 'done')
            ->where('frame.walletMinor', 3_500_00));

    $this->actingAs(enumerateRequester('Somebody Else', '08037770007'), 'portal')
        ->get("/enumerate/verifications/{$request->reference}")
        ->assertNotFound();
});

it('places a request through the page and lands on it', function () {
    $account = enumerateRequester();
    enumerateFund($account, 5_000);

    $response = $this->actingAs($account, 'portal')->post('/enumerate/verify', [
        'name' => 'SAHEL SOLAR SYSTEMS LIMITED',
        'rc_number' => '1739021',
        'company_type' => 'COMPANY',
        'tier' => 1,
    ]);

    $placed = EnumerateRequest::query()->sole();
    $response->assertRedirect("/enumerate/verifications/{$placed->reference}");

    $this->post('/enumerate/verify', ['name' => 'X', 'rc_number' => '1', 'company_type' => 'COMPANY', 'tier' => 3, 'days' => 30])
        ->assertSessionHasErrors('tier');
});

it('answers the search box from the register', function () {
    $account = enumerateRequester();

    $this->actingAs($account, 'portal')
        ->getJson('/enumerate/lookup?by=name&q=kora build')
        ->assertOk()
        ->assertJsonCount(2, 'matches')
        ->assertJsonPath('matches.0.rcNumber', '1482093')
        ->assertJsonPath('matches.1.status', 'Inactive');

    $this->getJson('/enumerate/lookup?by=rc&q='.FakeRegistry::UNAVAILABLE_RC)->assertStatus(503);
});

it('sends a signed-out visitor to Enumerate sign-in and back to Enumerate after', function () {
    $account = enumerateRequester();
    $account->forceFill(['password' => 'correct horse battery'])->save();

    $this->get('/enumerate/wallet')->assertRedirect('/enumerate/sign-in');
    $this->get('/enumerate/sign-in')->assertOk()->assertInertia(fn ($page) => $page->component('enumerate/SignIn'));

    // By phone number and password, as the Enumerate form asks.
    $this->post('/portal/sign-in/password', ['identifier' => '0803 666 0006', 'password' => 'correct horse battery'])
        ->assertRedirect(url('/enumerate/wallet'));
});

it('never lets the fake register answer in production', function () {
    new FakeRegistry(true);
})->throws(RuntimeException::class, 'cannot run in production');

it('reads Dojah as documented and keeps directors to a name and a role', function () {
    Http::fake([
        'sandbox.dojah.io/api/v1/kyc/cac/advance*' => Http::response(['entity' => [
            'company_name' => 'KORA BUILD SUPPLIES LIMITED',
            'rc_number' => '1482093',
            'type_of_company' => 'COMPANY',
            'status' => 'ACTIVE',
            'date_of_registration' => '2016-03-14T00:00:00.000Z',
            'address' => 'Plot 7, Ahmadu Bello Way, Garki',
            'affiliates' => [
                ['first_name' => 'Kolawole', 'last_name' => 'Ade', 'affiliate_type' => 'DIRECTOR', 'phone_number' => '08030000000', 'gender' => 'MALE'],
            ],
        ]]),
        'sandbox.dojah.io/api/v1/kyc/cac/tin*' => Http::response(['error' => 'Not found'], 400),
        'sandbox.dojah.io/api/v1/kyb/business/search*' => Http::response(['entity' => [
            ['name' => 'KORA BUILD SUPPLIES LIMITED', 'internationalNumber' => 'RC1482093', 'country' => ['name' => 'Nigeria', 'code' => 'NG']],
        ]]),
        'sandbox.dojah.io/api/v1/kyc/cac/basic*' => Http::response(['message' => 'down'], 503),
    ]);

    $dojah = new DojahRegistry('https://sandbox.dojah.io', 'app-123', 'secret-456');
    $company = $dojah->company('1482093', 'COMPANY');

    expect($company?->status)->toBe('Active')
        ->and($company?->incorporatedOn)->toBe('2016-03-14')
        ->and($company?->directors)->toBe([['name' => 'Kolawole Ade', 'role' => 'Director']])
        ->and(json_encode($company?->toFacts()))->not->toContain('0803')
        ->and($dojah->tin('1482093', 'COMPANY'))->toBeNull()
        ->and($dojah->search('name', 'kora')[0]->rcNumber)->toBe('1482093');

    Http::assertSent(fn ($request) => $request->hasHeader('AppId', 'app-123') && $request->hasHeader('Authorization', 'secret-456'));

    expect(fn () => $dojah->search('rc', '1482093'))->toThrow(RegistryUnavailable::class);
});

it('reads Prembly as documented, translates company types, and keeps directors to a name and a role', function () {
    Http::fake([
        'api.prembly.com/verification/cac/advance' => Http::response([
            'status' => true, 'response_code' => '00',
            'data' => [[
                'rc_number' => '1482093',
                'company_name' => 'KORA BUILD SUPPLIES LIMITED',
                'company_status' => 'ACTIVE',
                'company_address' => 'Plot 7, Ahmadu Bello Way, Garki',
                'entity_type' => 'RC',
                'registrationDate' => '2016-03-14T00:00:00Z',
                'directors' => [[
                    'surname' => 'Ade', 'firstname' => 'Kolawole', 'otherName' => 'N/A',
                    'email' => 'k@kora.ng', 'phoneNumber' => '08030000000', 'address' => '4 Home Street',
                    'affiliateTypeFk' => ['name' => 'DIRECTOR'],
                ]],
            ]],
        ]),
        'api.prembly.com/verification/tin' => Http::response([
            'status' => true, 'response_code' => '00',
            'data' => ['taxpayer_name' => 'KORA BUILD SUPPLIES LIMITED', 'cac_reg_number' => 'RC1482093', 'firstin' => '12392112-0001'],
        ]),
        'api.prembly.com/identitypass/verification/global/company/search' => Http::response([
            'status' => true, 'response_code' => '00',
            'data' => [['name' => 'KORA BUILD SUPPLIES LIMITED', 'internationalNumber' => 'RC1482093', 'countryCode' => 'ng']],
        ]),
        // A business name number: not a company, found on the second try.
        'api.prembly.com/verification/cac/basic' => function ($request) {
            return $request['company_type'] === 'BN'
                ? Http::response(['status' => true, 'response_code' => '00', 'data' => [
                    'rc_number' => '3300112', 'company_name' => 'IYA BOSE PROVISIONS', 'company_status' => 'Active',
                    'company_type' => 'BN', 'city' => 'Garki', 'state' => 'FCT',
                ]])
                : Http::response([], 400);
        },
    ]);

    $prembly = new PremblyRegistry('https://api.prembly.com', 'key-123', 'app-456');
    $company = $prembly->company('RC 1482093', 'COMPANY');

    expect($company?->status)->toBe('Active')
        ->and($company?->incorporatedOn)->toBe('2016-03-14')
        ->and($company?->directors)->toBe([['name' => 'Kolawole Ade', 'role' => 'Director']])
        ->and(json_encode($company?->toFacts()))->not->toContain('0803')
        ->and(json_encode($company?->toFacts()))->not->toContain('Home Street')
        ->and($prembly->tin('1482093', 'COMPANY')?->tin)->toBe('12392112-0001')
        ->and($prembly->search('name', 'kora')[0]->rcNumber)->toBe('1482093');

    $bn = $prembly->search('rc', '3300112');
    expect($bn)->toHaveCount(1)
        ->and($bn[0]->companyType)->toBe('BUSINESS_NAME')
        ->and($bn[0]->name)->toBe('IYA BOSE PROVISIONS');

    Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'key-123') && $request->hasHeader('app-id', 'app-456'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verification/tin') && $request['number'] === 'RC1482093' && $request['channel'] === 'CAC');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verification/cac/advance') && $request['company_type'] === 'RC' && $request['rc_number'] === '1482093');
});

it('treats a Prembly refusal as the provider being unavailable, and a miss as no record', function () {
    Http::fake([
        'api.prembly.com/verification/cac/advance' => Http::response(['status' => false, 'detail' => 'No record found'], 200),
        'api.prembly.com/verification/tin' => Http::response(['detail' => 'Insufficient wallet balance'], 402),
    ]);

    $prembly = new PremblyRegistry('https://api.prembly.com', 'key-123', '');

    expect($prembly->company('1482093', 'COMPANY'))->toBeNull()
        ->and(fn () => $prembly->tin('1482093', 'COMPANY'))->toThrow(RegistryUnavailable::class, 'Insufficient wallet balance');
});

it('refuses to start Prembly without its key', function () {
    new PremblyRegistry('https://api.prembly.com', '', '');
})->throws(RuntimeException::class, 'PREMBLY_API_KEY');
