# GeoVerify

Phase 1 of the GeoVerify programme: the Field Enumeration Platform. An offline-first
PWA for field officers, plus the supervisor console behind it.

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
- **Business Portal, Discovery Portal and Command Centre do not exist here.** Do
  not add routes, scaffolding or "future" placeholders for them.

## Stack

Laravel 12 / PHP 8.3+, PostgreSQL with PostGIS and h3-pg, Redis, Inertia v2 with
React 19 and TypeScript strict, MapLibre GL with PMTiles, Dexie for the field store,
Pest, Larastan level 6.

Inertia is pinned to v2 on both sides: `inertiajs/inertia-laravel ^2.0` with
`@inertiajs/react ^2.3`. The npm packages have a v3 line that pairs with
`inertia-laravel` v3; do not upgrade one side alone.

## Layout

Domain code lives under `app/Domain/{Coverage,Field,Registry,Identity,Media,Verification,Sync}`.
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
