# GeoVerify

Two phases of the GeoVerify programme live here.

**Phase 1, the Field Enumeration Platform**: an offline-first PWA for field
officers, plus the supervisor console behind it. Complete.

**Phase 2, the Business & Citizen Portal**: the self-service face of the
register. Business owners claim the listing we enumerated or register a new one,
and buy physical verification of it. Under construction. Plan in `_plan/phase-2/`.

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
- **Discovery Portal and Command Centre do not exist here.** No routes, no
  scaffolding, no "future" placeholders. Design the data so they remain
  possible; build nothing for them. (The Business Portal was on this list until
  Phase 2 was commissioned on 2026-08-27.)
- **No staff member self registers.** Fortify's registration feature stays off.
  Officers, supervisors and admins are created by an admin. Parties in the
  portal do self register, on their own guard, against `party_users`. The
  `users` table stays the staff table: `Role` is never widened to admit a
  member of the public.
- **A field-enumerated record is private until its party opts in.** Enumeration
  is not consent to publication. `enterprises.publication_state` defaults to
  `private`, and only `opted_in` is ever eligible for publication.
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

Three roles: `officer`, `supervisor`, `admin`. An officer holds assignments and
captures; a supervisor assigns and reviews; an admin also rules on escalations,
reads the audit log, manages people and devices, and contracts mandates.

Three surfaces, three middleware: `field` (`/field`), `supervises` (`/console`)
and `administers` (`/admin`). Per record access is `AssignmentPolicy` and
`DevicePolicy`.

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
`app/Domain/{Claim,Coverage,Field,Identity,Media,Party,Registry,Staff,Sync,Verification}`.
`Party` and `Claim` are Phase 2: parties, portal accounts, the access between
them, and the claim and dispute flow. `Staff` is the in-house side: creating,
suspending and reinstating the people who work this system.
Business rules go in action classes, not in controllers and not in models.

## Commands

```bash
php artisan test                                  # Pest, against PostgreSQL
./vendor/bin/pint                                 # formatting
./vendor/bin/phpstan analyse --memory-limit=1G    # Larastan level 6
npx tsc --noEmit && npx eslint .                  # frontend checks
npm run dev                                       # Vite
```

Setup, including the h3-pg build, is in `docs/setup.md`. The design and build plan
is in `_plan/design-plan.html`.
