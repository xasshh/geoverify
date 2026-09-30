# GeoVerify

Three phases of the GeoVerify programme live here.

**Phase 1, the Field Enumeration Platform**: an offline-first PWA for field
officers, plus the supervisor console behind it. Complete.

**Phase 2, the Business & Citizen Portal**: the self-service face of the
register. Business owners claim the listing we enumerated or register a new one,
and buy physical verification of it. Under construction. Plan in `_plan/phase-2/`.

**Phase 3, the public Discovery Portal**: the directory anybody can read, and
identity checks sold as a product. Surveyed in `_plan/phase-3/00-survey.md`,
with milestones P1 to P4 (the public directory is P2). There is no build plan
for it yet.

`README.md` is the stock Laravel file. The project is documented here, in
`CAMPAIGNS.md`, `docs/` and `_plan/`.

## Hard rules

- **No em dashes or en dashes anywhere.** Code, comments, copy, docs, commits.
  Use a colon, comma, parentheses or a full stop. Hyphens are fine.
- **All spatial computation happens in PostgreSQL.** No haversine in PHP, ever.
- **Never trust client-supplied ward, LGA or state.** Resolve server-side with
  `ST_Contains` against `admin_boundaries` on ingestion.
- **No raw NIN is stored anywhere.** No column, no log line, no cache entry. Hash
  it, keep the last four digits and the verification receipt, discard the number.
- **Nothing is hard-deleted.** Status changes append to `verification_events`.
- **Re-enumeration creates a new observation, never an overwrite.**
- **The sync endpoint is idempotent**, keyed on `client_uuid` plus payload hash.
- **The Command Centre does not exist here.** No routes, no scaffolding, no
  "future" placeholders. Design the data so it remains possible; build nothing
  for it. (The Business Portal was on this list until Phase 2 was commissioned
  on 2026-08-27. The Discovery Portal was on it until the public marketplace
  was commissioned on 2026-09-07.)
- **No staff member self registers.** Fortify's registration feature stays off.
  Officers, supervisors and admins are created by an admin. Parties in the
  portal do self register, on their own guard, against `party_users`. The
  `users` table stays the staff table: `Role` is never widened to admit a
  member of the public.
- **A business may publish photographs of itself; an officer's never leave.**
  Both live in `media`, kept apart by the `media_one_author` check constraint
  (exactly one of `captured_by` and `uploaded_by_party_id`). What publishes them
  is `kind = storefront` with a party author, asked for by name in the SELECT:
  never a query that takes everything and excludes the evidence, because that
  filter is one refactor away from publishing an interior shot with somebody's
  family in it. Withdrawing sets `status = withdrawn` and keeps the row.
  One narrow exception, decided on 2026-09-29 for paid inspections (Phase 4
  M3): media of kind `inspection`, taken by an agent of the goods or premises a
  buyer paid to have checked, attached to the `inspections` row and never to
  the building, are shown to that order's buyer and merchant on the order page
  through links that expire in minutes. Nowhere else: not the directory, not
  the investor portal, not an export. `InspectionFlowTest` holds it ("shows the
  agent photographs to the two parties and on no other surface"). A second,
  decided on 2026-09-29 for Enumerate (decision 1 of the flow document): on a
  site visit the officer files photographs as `visit_storefront`,
  `visit_signage`, `visit_interior` or `visit_other`, attached to the
  `enumerate_visits` row. The requester who paid sees the storefront and
  signage kinds only, asked for by name (`EnumerateVisit::exteriorPhotos`), only
  once a supervisor has accepted the visit, through links that expire. The
  interior is counted and never shown. Nor is any coordinate: the requester is
  told how far the premises are from the registered address, and the ward and
  LGA. `EnumerateVisitTest` holds it.

- **A field-enumerated record is private until its party opts in.** Enumeration
  is not consent to publication. `enterprises.publication_state` defaults to
  `private`, and only `opted_in` is eligible for full publication.

- **An unclaimed record appears in the directory in reduced form or not at
  all.** Narrowed from the rule above on 2026-09-07 so the public marketplace
  can exist. A `private` record may expose trading name, sector, ward and LGA,
  and only when the latest observation recorded `signage_observed`: a business
  that put its name on the street has published that much itself, and one that
  did not has published nothing. Never the phone, never the email, never a
  photograph, never an exact coordinate, whatever the publication state. The
  reduced projection is fixed in the SELECT and asserted by test, like the claim
  search it is modelled on.

- **Only a claimed and opted-in listing is indexable.** Unclaimed reduced
  listings render `noindex`. Indexing is the step that cannot be taken back, so
  it waits for consent, and becoming findable is what a business gets for
  claiming. Every directory surface offers removal without requiring a claim,
  which writes `withheld`.

- **Every directory query goes through `DirectoryVisibility`.** One `FROM` and
  one `WHERE`, shared by the rows, the sector counts and the ward breakdown. A
  count computed from a slightly different predicate than the list is how a page
  says "14 pharmacies" over a list of nine, or counts a business that asked not
  to be here. Aggregates are counted over the directory-visible population only,
  never the register: a count over everything would describe businesses that
  chose not to appear. Wards below three fold into "elsewhere" (with the
  unresolved ones), and businesses with no sector are reported as a total, so
  every breakdown adds up to the number above it.

- **The directory has three depths and `SearchDirectory` owns all of them.**
  `reduced` (unclaimed, signage observed), `claimed` (control proved and opted
  in) and `verified` (an officer attended). The listing page resolves through
  the same action as the list, so a detail page can never show what the list
  withheld, and `DirectoryTest` names the whole projection key by key: a key
  appearing there that is not in the list is a disclosure somebody added without
  deciding to. Removal by a stranger is honoured (`WithholdOnRequest`) unless an
  owner opted in deliberately, in which case the request is recorded for a
  supervisor instead. Publishing takes proof; being left alone does not.
  What an owner states (`business_profiles`: hours, delivery, street address)
  and buyers' ratings appear at the claimed depth only. Directions is a map
  search for the name, the owner's address, ward and LGA, never our
  coordinate; "nearest" measures to the cell centre the pin already shows; the
  map box matches an unclaimed listing only by its ward.
- **Money moves only on a signed provider webhook.** Not on a callback, not on
  a redirect, not on anything a customer's browser can reach. `RecordPayment`
  is reachable from `HandlePaymentWebhook` and nowhere else.
- **Never the word "escrow".** Not in customer copy, not in state names, not in
  identifiers. It is a regulated term in Nigeria and we are not licensed for it.
  Funds are held and released: a completion-linked payment.
- **Do not refactor the field platform to suit the portal.** Officers are in the
  field against a shipped client and the sync contract is fixed. If the portal
  needs something, extract a shared service and leave the field code calling the
  same behaviour.
- **People and devices are suspended or revoked, never deleted.** An officer's
  captures must stay attributable after they leave.

## Stack

Laravel 12 / PHP 8.3+, PostgreSQL with PostGIS and h3-pg, Redis, Inertia v2 with
React 19 and TypeScript strict, MapLibre GL with PMTiles, Dexie for the field store,
Pest, Larastan level 6.

Inertia is pinned to v2 on both sides: `inertiajs/inertia-laravel ^2.0` with
`@inertiajs/react ^2.3`. The npm packages have a v3 line that pairs with
`inertia-laravel` v3; do not upgrade one side alone.

## Roles and access

Three staff roles: `officer`, `supervisor`, `admin`. An officer holds assignments
and captures; a supervisor assigns and reviews; an admin also rules on
escalations, reads the audit log, manages people and devices, and contracts
mandates.

Four session guards, and they never overlap: `web` (staff, against `users`),
`portal` (parties, against `party_users`), `client` (the commissioning body,
against `client_users`) and `investor` (investor organisations, against
`investor_users`). A portal, client or investor session cannot satisfy
`supervises` by construction rather than by check, which is the entire reason
there are four rather than one table with a wider `Role`.

Six surfaces, six middleware aliases, all registered in `bootstrap/app.php`:
`field` (`/field`), `supervises` (`/console`), `administers` (`/admin`),
`portal` (`/portal`, and Enumerate's `/enumerate` on the same guard), `client`
(`/client`) and `investor` (`/invest`).
`investor:verified` additionally requires the organisation to have passed KYC,
which an admin rules on at `/admin/investors`; everything that names a business
sits behind it. Per record access is `AssignmentPolicy` and `DevicePolicy`.

`Role::supervises()` is true for an admin too, so the admin views are a separate
route group behind `administers` rather than a section of the console. That
separation is load bearing: escalation exists so the supervisor who suspects a
capture was fabricated is not the person who rules on it, and
`ResolveEscalation` refuses a ruling from whoever raised it.

A supervisor who reaches `/admin` is refused, not redirected. An officer who
reaches `/console` is redirected to their own work: the first went looking, the
second took a wrong turn.

Devices carry their own revocable Sanctum token scoped to `field:capture`, so a
lost handset is cut off without touching the person's account.

Five things answer with no session at all, each for a stated reason.
`webhooks/paystack` is authenticated by the provider's signature over the body
and is named individually in the CSRF exemption list in `bootstrap/app.php`, so
a second route cannot join that exemption by being filed beside it.
`media/file/{path}` is signed and short lived, standing in on a local disk for
the presigned object storage link it replaces. `verify/{token}` is how the
holder of a printed certificate checks it without an account, so the token is
forty random characters rather than anything derivable from the reference
printed next to it, and what may be disclosed is decided in
`ResolvePublicVerification` rather than in the controller. The same route
answers an Enumerate report's QR code (`enumerate_requests.report_token`,
minted on the first print) with less than the report itself says: the
business, tier, finding, score, date and ward, and nothing from the registry
or the daily log.
`receipts/{token}` is a person's copy of the consent they gave, open on a
forty-eight character token because somebody asked at their door may never have
had an account here. The print routes below are signed, short lived and refused
off the loopback interface.

Behind a load balancer or a tunnel, name it in `TRUSTED_PROXIES` (see
`config/app.php`). Left empty, forwarded headers are ignored, the app generates
`http://` URLs on an `https://` page and reads every visitor's address as the
proxy's, which quietly turns the portal's per address rate limits into one
global limit.

Local sign in after `php artisan db:seed --class=FieldTeamSeeder`:
`supervisor@geoverify.test`, `bello@geoverify.test` and the rest, password
`password`.

## Layout

Domain code lives under
`app/Domain/{Campaign,Catalogue,Claim,Commerce,Coverage,Enumerate,Field,Identity,Investment,Ledger,Media,Party,Registry,Staff,Sync,Verification}`.
`Enumerate` is the verification portal at `/enumerate` (flow document:
"Enumerate Platform: How the Three Portals Work Together"): anybody pays from a
prepaid wallet to have any business checked by its CAC number. The same portal
accounts and `portal` guard, behind its own door. The subject is a CAC record,
not a row of `enterprises`, and nothing is written to the register on a
stranger's say-so. `RegistryLookup` is the one way to CAC and FIRS: `dojah` in
anything real, `fake` (a fixed list that refuses production) in development and
tests, chosen by `REGISTRY_DRIVER`. Drivers report what the register said;
`RunRegistryChecks` alone decides what matches, and directors are kept to a name
and a role. A supervisor reads the answers at `/console/desk-checks`
(`DecideDeskCheck`): a desk check that ends the job earns `registry_fee_minor`
and returns the rest of the price to the wallet. Wallet balances are sums over
`liability.requester_wallets`, attributed by `ledger_entries.wallet_id` (an
owner beside the one subject); a top-up (`FND-...`) grows only on the signed
webhook, and spending held credit is a ledger movement that needs none.
Tier 2 and 3 visits are `enumerate_visits` rows, never `assignments`: a
supervisor pins the premises at `/console/enumerate-visits`, the officer files
from `field/Visit` through the same job outbox as an inspection, and a site
visit is earned when a supervisor accepts it. A Tier 3 then runs daily visits
(`kind = monitoring`, `ManageMonitoring`) on trading days, Monday to Saturday
less public holidays, opened each morning by `enumerate:schedule-monitoring`.
A day nobody filed is `missed`; closing pays back the missed trading days'
share of the price. `ScoreEnumerateRequest` gives the score (registry 30,
location 40, activity 30, out of what the tier checks) live while a request
runs and stamps it when the request finishes; a stamped score never moves.
Support threads (`enumerate_tickets`, append-only messages) are answered at
`/console/support`, and a refund there is an administrator's ruling only.
Organisations (E5) are sets of the same portal accounts with roles (admin,
project lead, requester, viewer, asked through `EnumerateMember::may`) and a
wallet of their own; a wallet has exactly one owner. `EnumerateContext` says
whether a person is acting for themselves or an organisation, from the
session, rechecked on every read, and every controller that spends or lists
money asks it for the wallet. An admin approves an organisation at
`/admin/enumerate-organisations` before bulk verification
(`ImportBulkVerification`: every line read first, the batch paid in full or not
at all) and projects open. A project runs as a campaign, linked by
`enumerate_projects.campaign_id`, and the organisation sees it through
`AssembleCampaignDossier`, so never the commercials.
`Party` and `Claim` are Phase 2: parties, portal accounts, the access between
them, and the claim and dispute flow. `Investment` is Phase 4 (plan in
`_plan/phase-4/`): investor organisations, the opportunities a business
publishes, data rooms and their grants. Every investor read goes through
`ReadOpportunities` (one FROM, one WHERE, like `DirectoryVisibility`), and a
business reaches investors only by publishing an opportunity itself. An
investor may pay for a verification visit: `verification_orders` has exactly
one payer, a party or an investor organisation, held by check constraint.
`Catalogue` is the merchant hub's product list: party-authored outright, its
photographs `media` of kind `product` with a party author, and public only on a
published listing through `SearchDirectory::productsFor`, which returns nothing
at the reduced depth.
`Commerce` is the merchant hub's buying (Phase 4 M2): product orders priced
from the catalogue, paid through the same signed webhook (it routes by
reference: `GV-2026-000123` verification, `GV-10482` product, `gvpo-...`
withdrawal), held in `BUYER_FUNDS_HELD`, released to `MERCHANT_BALANCES` on the
buyer's word, a ruling, or `orders:release-delivered`. Enumerate wallet
top-ups (`FND-20260923-7H2Q`) arrive through the same webhook. Wallet balances are sums
over the ledger, never a column. Field messaging lives in `Field`
(`FieldMessaging`, `field_messages`): an officer's supervisor is whoever
assigned their newest open cell, so there is no team table; it is its own
channel beside the sync contract, never part of it.
`Staff` is the in-house side: creating, suspending and reinstating the people
who work this system. `Ledger` is the
double-entry record behind paid verification: one signed `amount_minor` column,
append-only by database trigger, and `PostTransaction` is the only writer.
Business rules go in action classes, not in controllers and not in models.

`Campaign` is the layer above enumeration: a commissioned exercise, its
mandates, its declared schema and its stakeholders. A campaign's areas *are*
`coverage_areas`: there is no `campaign_areas` table and there is not going to
be one. Commercials are admin-only and `AssembleCampaignDossier`, which builds
every client-facing payload, has no code path to `campaign_commercials` at all.
The full model is in `CAMPAIGNS.md`; read it before touching anything under
`app/Domain/Campaign` or `app/Http/Controllers/Client`.

Controllers render Inertia pages that mirror the route group:
`resources/js/pages/{admin,auth,client,console,field,portal,public}/*.tsx`,
resolved by name in `resources/js/app.tsx`. `Inertia::render('console/Review')`
means `resources/js/pages/console/Review.tsx`, so a renamed page needs both
sides. Two pages sit outside the groups: `Health.tsx` behind `/`, which reports
whatever `CheckSpatialStack` finds, and `Design.tsx` behind `/design`, the
primitives gallery that is only routed when the application is local. Pages
resolve lazily so an officer does not parse MapLibre and the whole console
before seeing their assignments, which stays safe offline only because the
service worker precaches every emitted chunk.

The sync contract's idempotency lives in `ProcessMutationBatch`: a
`client_uuid` plus `payload_hash` lookup against `sync_receipts` short circuits
a replay, and a child arriving before its parent throws `DeferredMutation` to
be retried later in the same batch rather than rejected.

The client half of that contract is `resources/js/lib/offline`: `db.ts` is the
Dexie schema, `queue.ts` the outbound mutation queue the sync endpoint answers,
`pack.ts` the stored PMTiles pack, and the two hooks beside them are what
screens actually consume. `lib/pwa.ts` registers the worker with
`registerType: 'prompt'`, so an update waits for the officer instead of
reloading the app part way through a building.

`config/geoverify.php` is short and load bearing: the tier freshness thresholds
that `ResolveListingTier` and `IssuePublicVerification` both read, so a scanned
QR code and a listing page cannot disagree about whether a check is still
current, and the public holidays `WorkingDays` counts against when the SLA sweep
decides an order is late.

## Documents a browser prints

Five documents leave the system as PDF: the evidence pack, the campaign brief,
the verification certificate, the consent receipt and the Enumerate Business
Verification Report. All five take one path, and a sixth should join it rather
than grow a second pipeline. The report is assembled only from
`PresentEnumerateRequest::page`, so it can never print what the requester's
page withholds; its photographs are embedded as data URIs because the printing
browser has no session to fetch a link with. A Blade view in
`resources/views/exports` is served by a signed, short lived HTML route
registered outside the guard group that owns the feature, because the headless
browser fetching it has no session. `PdfRenderer` drives Chromium over that URL
and `LoopbackPrint` builds it, pointing at `127.0.0.1` on the port the request
arrived on rather than at `APP_URL`: a document that only prints when DNS agrees
with itself is a document that fails in production. Set `CHROMIUM_BINARY` when
the browser is not in one of the usual places, and `services.chromium.base_url`
when loopback is not where the application answers, as in a container.

Printing needs the application to answer a second request while the first is
still open, because Chromium fetches the document from the application that
launched it. One worker waits on itself until the Chromium timeout, and
`php artisan serve` has one: it ignores `PHP_CLI_SERVER_WORKERS` unless
`--no-reload` is passed too. That is why `composer run dev` runs
`PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload`, why a bare
`php artisan serve` (as in the browser test instructions below) times out on
every PDF, and why production needs more than one php-fpm worker.

## Commands

```bash
composer run dev            # server (:8000, four workers), queue, pail logs, Vite
npm run dev                 # Vite alone

./vendor/bin/pint                                 # formatting
./vendor/bin/phpstan analyse --memory-limit=1G    # Larastan level 6
npm run types && npm run lint                     # tsc --noEmit, then ESLint
```

`pint.json` adds `declare_strict_types` and `strict_comparison` to the Laravel
preset, so Pint rewrites `==` as `===`. Write the strict form yourself: a
formatter changing a comparison is a behaviour change nobody reviewed.

### Tests

```bash
php artisan test                                  # Pest, against PostgreSQL
php artisan test --filter=ClaimFlowTest           # one file
php artisan test --filter='arrive shuffled'       # one test by name
php artisan test tests/Feature/SyncTest.php       # one path
php artisan test --group=load                     # the load suite, excluded by default
```

Feature tests need a real PostgreSQL database named `geoverify_testing` with
PostGIS and h3-pg. There is no SQLite fallback and there will not be one: this
system's behaviour is defined by spatial predicates, so a suite that does not
exercise them proves nothing. `RefreshDatabase` is applied to `Feature` only
(`tests/Pest.php`); `tests/Unit` runs without a database.
`ServiceWorkerPrecacheTest` skips rather than fails when `public/sw.js` has not
been built, so run `npm run build` before the suite or the offline precache
check never runs. CI builds first.

The `load` group is excluded in `phpunit.xml` because it spends about two
minutes: one proving the sync endpoint holds at two thousand mutations, one
searching a generated register of fifty thousand businesses. Run the first
before touching `ProcessMutationBatch` and the second before touching
`SearchRegister`.

`ClaimSearchLoadTest` generates its register in SQL and reports numbers rather
than asserting a plan, because the plan is not always the same and should not
be. A distinctive name is answered from the trigram index; a name built from
the words every shop uses (Stores, Ventures, Enterprises) matches thousands of
rows, and PostgreSQL is right to read the table for it. What is held to a
budget there is the answer, not the plan.

Browser tests are Playwright against a running application, serial by design
(one development database, so two at once are two people claiming the same
shop):

```bash
php artisan serve --port=8123          # or set GV_BASE_URL
npx playwright test                    # tests/Browser
npx playwright test tests/Browser/claim-flow.spec.ts
```

Unlike the Pest suite these run against the *development* database and leave
their marks in it. The claim, correction and order specs each consume one
unclaimed listing per run and never give it back. The correction spec draws
from a disjoint pool (it wants a listing whose latest observation has no
phone); the claim and order specs share one and take from opposite ends of it,
oldest and newest, so a single run of the suite does not have them fighting for
the same shop. Once a pool is empty the spec fails in its own helper with a
JSON parse error, which is exhaustion and not a regression. Reseed to refill it.

They also need a register to exist at all: a coverage area, its cells and a
seeded field day. On a machine that has never run the data pipeline these specs
cannot run, and the failure looks like an empty pool rather than a missing one.

Every spec reaches the application from 127.0.0.1, so they share one bucket in
every per address rate limit. `portal.claim.code` allows ten a minute, and the
suite spends three or four of those; running single specs repeatedly and then
the whole suite inside the same minute can cross it. A run that stops waiting
for "we sent a code", with no error in `storage/logs/laravel.log`, is that
limit refusing the request and Inertia showing the 429 in its error frame.
Wait a minute and run it again rather than reaching for the throttle: it guards
an endpoint that sends codes to real phones.

### Continuous integration

`.github/workflows/ci.yml` runs the gates in this order, and any one of them
fails the build: `pint --test`, `phpstan analyse`, `tsc --noEmit`, `eslint .`,
`php artisan test`, then Playwright. PHP 8.3 and 8.5 are both held green, 8.3
being the brief's target and 8.5 what the development machine runs. The browser
suite runs on 8.3 only, under `APP_ENV=local` because the design gallery and the
field client are local only routes, and after `FieldTeamSeeder` because those
specs need somebody to sign in as. Its screenshots are uploaded as an artifact.

The database is not a published image: `docker/postgres/Dockerfile` compiles
h3-pg against PostGIS at a pinned commit, and the workflow pins the same commit
in `H3PG_COMMIT`. Change one and change the other, or the image CI builds stops
being the image `docs/setup.md` describes.

### Data pipeline and operations

The register is built by commands, in this order, and `docs/geodata.md` covers
the sources:

```bash
php artisan geoverify:boundaries-load <path> --preset=  # first, always
php artisan geoverify:taxonomy-load                     # ISIC sectors and aliases
php artisan geoverify:coverage-create --lga-code=        # a mandate
php artisan geoverify:grid-generate <area>              # its H3 cells
php artisan geoverify:footprints-ingest <area> --path=   # buildings
php artisan geoverify:roads-ingest --path=              # streets, for map landmarks
php artisan geoverify:pack-build <area>                 # the offline PMTiles pack
php artisan geoverify:score                             # confidence over captures
php artisan orders:sweep-sla --dry-run                  # SLA refunds, daily at 07:00
php artisan geoverify:reconcile-ledger --from= --to=    # provider drift, daily at 07:30
php artisan enumerate:schedule-monitoring --date=       # Tier 3 daily visits, daily at 06:00
```

`geoverify:reconcile-ledger` exits non-zero when the ledger and the payment
provider disagree, which is what makes it worth scheduling: the failure is the
notification. It compares successful charges against the cash legs posted on
receiving them, reports refunds without matching them, and changes nothing. A
correction to the ledger is a posted movement, made by somebody who has looked.

Seeders: `FieldTeamSeeder` (staff sign in), `VerificationPricingSeeder` (prices
and ledger accounts), `CampaignSeeder`, `FieldDaySeeder`.

Setup, including the h3-pg build, is in `docs/setup.md`. The development machine
has no Docker runtime: it runs Homebrew PostgreSQL 17 with PostGIS 3.6 and a
native Redis (option B there), and photographs go to a private local disk rather
than the MinIO bucket in `docker-compose.yml`. The Phase 1 design and
build plan is in `_plan/design-plan.html`; Phase 2 is in `_plan/phase-2/`.
