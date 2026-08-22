# Setting up GeoVerify

Phase 1 is the Field Enumeration Platform: the offline-first field PWA and the
supervisor console behind it. Nothing else in the programme is built here.

## What the machine needs

| Requirement | Why |
|---|---|
| PHP 8.3 or later | Framework runtime. CI holds both 8.3 and 8.5 green. |
| PostgreSQL 16 or later | Everything spatial. There is no substitute. |
| PostGIS 3.4 or later | Geometry, GiST indexes, `ST_Contains` ward resolution. |
| h3-pg | `h3_polygon_to_cells`. Without it there is no grid, so no work list. |
| Redis | Queues, cache, Horizon. |
| Node 22 or later | Vite, React, TypeScript. |

SQLite and MySQL cannot run this system. Neither provides PostGIS or H3, so the
grid generator, ward resolution, trace geometry and duplicate detection all fail.
The test suite therefore runs against PostgreSQL too, not an in-memory database.

## Option A: Docker (the intended path)

```bash
docker compose up -d
cp .env.example .env && php artisan key:generate
composer install && npm ci
php artisan migrate
npm run build
```

The database image is built from `docker/postgres/`, because no published PostGIS
image bundles h3-pg. It compiles the extension from a pinned commit and enables it
on both `geoverify` and `geoverify_testing` on first start.

## Option B: Native (what this machine currently uses)

There is no Docker runtime on the current development machine, so the stack runs
against a native Homebrew PostgreSQL 17 with PostGIS 3.6, plus a native Redis.

Build and install h3-pg once:

```bash
brew install cmake h3
git clone https://github.com/zachasme/h3-pg.git && cd h3-pg
git checkout 04227cb62a7338eb2a655fff875b125de2f043f4

# On macOS the PostGIS module is named postgis-3.dylib, which CMake's find_library
# does not match, so point it at the file directly.
cmake -B build -DCMAKE_BUILD_TYPE=Release \
  -DPOSTGIS=$(pg_config --pkglibdir)/postgis-3.dylib
cmake --build build --parallel
cmake --install build
```

Then create the databases and run the app:

```bash
createdb geoverify && createdb geoverify_testing
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

Object storage is the one difference from Docker. In native development,
photographs use a private local disk behind the same signed temporary URL contract
that S3 uses in Docker and production, so no application code differs between them.

## Verifying the spatial stack

The first migration enables the extensions and refuses to complete unless H3 cell
generation actually returns cells. To check by hand:

```bash
psql -d geoverify -c "
  select count(*) from h3_polygon_to_cells(
    st_geomfromtext('POLYGON((7.44 9.03,7.50 9.03,7.50 9.08,7.44 9.08,7.44 9.03))',4326), 9
  )"
```

385 cells over that Abuja Municipal test box means the stack is sound. The route at
`/` reports the same three numbers through the application.

## Everyday commands

```bash
php artisan test                 # Pest, against PostgreSQL
./vendor/bin/pint                # formatting
./vendor/bin/phpstan analyse --memory-limit=1G   # Larastan level 6
npx tsc --noEmit                 # TypeScript strict
npx eslint .                     # ESLint
npm run dev                      # Vite
```

## A note on versions

`h3` reports its version as `unreleased`. The extension is built from a pinned
commit of the upstream repository rather than a release tag, because release tags
were not reachable from the build environment. The commit is fixed in
`docker/postgres/Dockerfile` and in CI, so builds are reproducible; the version
string is cosmetic, not a sign of drift.
