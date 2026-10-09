<?php

declare(strict_types=1);

use App\Domain\Enumerate\Registry\CompanyRecord;
use App\Domain\Enumerate\Registry\RegistryLookup;
use App\Domain\Enumerate\Registry\RegistryMatch;
use App\Domain\Enumerate\Registry\RegistryUnavailable;
use App\Domain\Enumerate\Registry\TinRecord;
use App\Domain\Party\Actions\ManagePortalCredentials;
use App\Domain\Party\Actions\NormalisePhone;
use App\Domain\Party\Models\PortalAccount;
use App\Models\User;
use Database\Seeders\VerificationPricingSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

/**
 * The public front: GeoVerify's home page, and Enumerate's landing and search.
 *
 * What matters: a signed-in requester's /enumerate is exactly what it was, a
 * signed-out visitor sees the landing page instead of a sign-in form, and the
 * free search costs at most one paid lookup per question per week and says
 * plainly when the register cannot answer.
 */
beforeEach(function () {
    $this->seed(VerificationPricingSeeder::class);
    config()->set('services.registry.driver', 'fake');
});

/** A registry that counts how often it is asked. */
function countingRegistry(?Throwable $fails = null): object
{
    $registry = new class($fails) implements RegistryLookup
    {
        public int $asked = 0;

        public function __construct(private readonly ?Throwable $fails) {}

        public function provider(): string
        {
            return 'counting';
        }

        public function search(string $by, string $term): array
        {
            $this->asked++;

            if ($this->fails !== null) {
                throw $this->fails;
            }

            return [new RegistryMatch('KORA BUILD SUPPLIES LIMITED', '1482093', 'COMPANY', 'ACTIVE', 'Abuja')];
        }

        public function company(string $rcNumber, string $companyType): ?CompanyRecord
        {
            return null;
        }

        public function tin(string $rcNumber, string $companyType): ?TinRecord
        {
            return null;
        }
    };

    app()->instance(RegistryLookup::class, $registry);

    return $registry;
}

it('shows the public home page at the root, and the spatial check at /health', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('public/Home')->has('prices.tier1'));

    $this->get('/health')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Health'));
});

it('shows a signed-out visitor the Enumerate landing page instead of a sign-in form', function () {
    DB::insert(
        "INSERT INTO coverage_areas (client_name, name, status, state_code, accuracy_threshold_m, default_h3_resolution, boundary, created_at, updated_at)
         VALUES ('Test', 'Makurdi', 'active', 'NG007', 15, 8, ST_Multi(ST_GeomFromText('POLYGON((8.5 7.7, 8.6 7.7, 8.6 7.8, 8.5 7.7))', 4326)), now(), now())",
    );

    $this->get('/enumerate')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('enumerate/Landing')
            ->where('coveredStates', ['NG007'])
            ->where('prices.tier1', fn (int $minor) => $minor > 0));

    // Every other Enumerate page still asks a visitor to sign in.
    $this->get('/enumerate/wallet')->assertRedirect('/enumerate/sign-in');
});

it('keeps a signed-in requester\'s /enumerate exactly as it was', function () {
    $account = app(ManagePortalCredentials::class)
        ->registerBuyer((new NormalisePhone)('08036660006'), 'Adaeze Nwosu', null);
    assert($account instanceof PortalAccount);

    $this->actingAs($account, 'portal')->get('/enumerate')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('enumerate/Home'));
});

it('answers the free search from the register, then from a week-long cache', function () {
    /** @var object{asked: int} $registry */
    $registry = countingRegistry();

    $this->getJson('/enumerate/search?by=name&q=Kora%20Build')
        ->assertOk()
        ->assertJsonPath('matches.0.rcNumber', '1482093')
        ->assertJsonPath('cached', false);

    // The same question, differently spaced and cased: no second paid lookup.
    $this->getJson('/enumerate/search?by=name&q=kora%20%20BUILD')
        ->assertOk()
        ->assertJsonPath('cached', true);

    expect($registry->asked)->toBe(1);
});

it('says plainly when the register cannot answer, without a stack trace', function () {
    countingRegistry(new RegistryUnavailable('down'));
    Cache::flush();

    $this->getJson('/enumerate/search?by=rc&q=1482093')
        ->assertStatus(503)
        ->assertJsonPath('message', 'The register is not answering just now. Try again in a minute.');

    countingRegistry(new RuntimeException('DOJAH_APP_ID and DOJAH_SECRET_KEY must both be set'));

    $this->getJson('/enumerate/search?by=rc&q=7654321')
        ->assertStatus(503)
        ->assertJsonMissing(['message' => 'DOJAH_APP_ID and DOJAH_SECRET_KEY must both be set']);
});

it('answers politely on a server with no registry keys yet', function () {
    // The real driver refuses to be built without its keys, as on a server
    // that has not been given them.
    config()->set('services.registry.driver', 'dojah');
    config()->set('services.dojah.app_id', '');
    app()->forgetInstance(RegistryLookup::class);

    $this->getJson('/enumerate/search?by=name&q=Kora%20keys')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Search is not available just now. Sign in to run a check.');
});

it('limits the free search per connection, by the minute', function () {
    countingRegistry();

    foreach (range(1, 8) as $i) {
        $this->getJson("/enumerate/search?by=name&q=business%20{$i}")->assertOk();
    }

    $this->getJson('/enumerate/search?by=name&q=business%209')->assertStatus(429);
});

it('shows the become-an-agent page, which creates no account', function () {
    $this->get('/become-an-agent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('public/BecomeAgent'));

    expect(User::query()->count())->toBe(0);
});

it('publishes a privacy policy and terms for Google\'s consent screen', function () {
    $this->get('/privacy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('public/Privacy'));
    $this->get('/terms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('public/Terms'));
});
