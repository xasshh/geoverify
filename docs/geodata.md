# Reference geography and footprint sources

Everything here is loaded reference data. None of it is created in the app, and all
of it is replaceable by a client's own authoritative boundaries.

## Administrative boundaries

| Level | Source | Count | Licence |
|---|---|---|---|
| State | OCHA Common Operational Datasets (`cod-ab-nga`) | 37 | CC BY 3.0 IGO |
| LGA | OCHA Common Operational Datasets (`cod-ab-nga`) | 774 | CC BY 3.0 IGO |
| Ward | GRID3 NGA Operational Wards v3.0 | 5,872 national | CC BY-SA 4.0 |

Wards come from GRID3 because the OCHA ward layer covers only Borno, Adamawa and
Yobe: 714 wards, all in the North East humanitarian operation, and none in the FCT.
GRID3 is the only open national ward layer.

Attribution is required by both licences and belongs in any published export.

### Preparing the files

The loader reads GeoJSON only, so it carries no GDAL dependency. Converting other
formats is a one-off step:

```bash
# States and LGAs: already GeoJSON inside the OCHA archive.
unzip nga_admin_boundaries.geojson.zip
cp nga_admin1.geojson storage/app/geodata/nga_states.geojson
cp nga_admin2.geojson storage/app/geodata/nga_lgas.geojson

# Wards: GeoPackage, and 190 MB nationally. Extract one state at a time.
ogr2ogr -f GeoJSON storage/app/geodata/fct_wards.geojson \
  GRID3_NGA_operational_wards_v3_0.gpkg \
  -where "statecode = 'FC'" -t_srs EPSG:4326 -nln wards
```

### Loading

```bash
php artisan geoverify:boundaries-load storage/app/geodata/nga_states.geojson --preset=ocha-state
php artisan geoverify:boundaries-load storage/app/geodata/nga_lgas.geojson   --preset=ocha-lga
php artisan geoverify:boundaries-load storage/app/geodata/fct_wards.geojson  --preset=grid3-ward
```

Each run is idempotent. Interrupt one and run it again.

### The hierarchy is resolved spatially, not by name

Parent links come from `ST_Contains` against a representative interior point, never
from matching source name strings. OCHA calls the Abuja LGA **Abuja Municipal**;
GRID3 calls the same ground **Municipal Area Council**. Name matching fails on that
pair. Containment does not.

`admin_boundaries.source_parent_name` records what the source itself claimed, so
disagreements stay visible:

```sql
SELECT w.name, w.source_parent_name AS source_says, lga.name AS resolved
  FROM admin_boundaries w
  JOIN admin_boundaries lga ON lga.id = w.parent_id
 WHERE w.level = 'ward' AND lower(w.source_parent_name) <> lower(lga.name);
```

For the FCT that returns four wards where OCHA and GRID3 genuinely disagree about
which LGA the ground belongs to, including one that GRID3 places in Abaji and OCHA
places in Kogi State entirely. These are findings a client can challenge, so they
are recorded rather than smoothed over.

### Ward codes are never truncated

Wards have no published pcode, so the code is derived from state, LGA and ward name.
It is stored in full. Truncating it to 32 characters collides five distinct wards
nationally, among them `Balogun Fulani I`, `II` and `III`, and `Madomawa East`
against `Madomawa West`. A collision silently merges two wards, which would resolve
captures into the wrong one.

## Building footprints

| Source | Coverage | Confidence published | Licence |
|---|---|---|---|
| Microsoft Global ML Building Footprints | Global, quadkey partitioned | **No** for Nigeria (`-1`) | ODbL |
| Google Open Buildings v3 | Africa and South Asia, S2 partitioned | Yes, 0 to 1 | CC BY 4.0 |

Microsoft publishes `confidence: -1` for Nigeria, meaning unscored. That is stored
as `NULL`, not as a number, because inventing a confidence the source never gave
would corrupt every downstream judgement built on it. Google publishes real scores;
prefer it where per-building confidence matters.

Find the files covering a mandate from Microsoft's index at
`https://minedbuildings.z5.web.core.windows.net/global-buildings/dataset-links.csv`,
matching on the Bing quadkey for the mandate's bounding box. Abuja Municipal is
covered by four zoom-9 quadkeys: `122221030`, `122221031`, `122221032`, `122221033`,
about 119 MB compressed and 1.6 million footprints, of which roughly 489,000 fall
inside AMAC.

```bash
php artisan geoverify:footprints-ingest 1 --source=microsoft_global_ml \
  --path=122221030.csv.gz --path=122221031.csv.gz \
  --path=122221032.csv.gz --path=122221033.csv.gz
```

### What decides membership

A footprint belongs to the mandate when its representative interior point is inside
the mandate boundary. The same point decides which H3 cell it is filed under.

Using `ST_Intersects` on the polygon instead admits buildings that straddle the
boundary but sit mostly outside. Those are then counted in `external_footprints`
while belonging to no cell, so the footprint total and the sum of the per-cell
denominators disagree. One predicate for both makes that impossible.

### Footprints are a work list, not a record

The sources miss buildings, merge adjacent ones and occasionally invent them. An
officer can add a structure with no matching footprint, and can dismiss a footprint
that is not a building. Dismissed footprints stop counting toward the denominator
but are never deleted: a rejected detection is evidence about the source's quality.
