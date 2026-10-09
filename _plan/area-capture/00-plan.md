# Area and natural feature capture: survey and plan

Brief: "Area & Natural Feature Capture Mode". First campaign: a Benue project
over land with few or no buildings (forest, grassland, farmland, rivers,
wetlands, water points, tracks). Written 2026-10-07. Nothing is built until
this plan is approved, and each of the five stages stops for review.

The rule over all of it: **the building workflow does not change.** Area
capture is an additional mode switched on per campaign. Existing records,
scoring, cell progress and the sync contract behave exactly as before, and
the building suites pass unchanged.

---

## Part 1: what exists today

### Building captures

- `structures` is the building. `structure_observations` holds each visit to
  it. Re-enumeration adds an observation and never overwrites one.
- Geometry: `structures.footprint geometry(Polygon, 4326)` (nullable) and
  `structures.centroid geography(Point, 4326)`, both GIST indexed.
- Every structure carries:
  - `grid_cell_id`, `coverage_area_id` and `h3_index`
  - ward, LGA and state resolved server-side with `ST_Contains`
    (`ResolveAdminHierarchy`)
  - `client_uuid` (unique), and a 0 to 100 `confidence_score`
- Building footprints from Google or Microsoft live in `external_footprints`.
  They are the work list and the denominator for cell completion.
- Photographs are rows in `media`, which is polymorphic (`mediable_*`) with a
  `kind`. The `media_one_author` check keeps officer evidence and
  party-uploaded images apart. **There is no bearing column today.**
- History: `verification_events` is polymorphic (`subject_*`, `event`, actor,
  `evidence` jsonb). Truncate is blocked by a trigger, and nothing is ever
  deleted.

### Campaigns and mandates (see CAMPAIGNS.md)

- `campaigns` sit above `coverage_areas`, and **a campaign's areas are its
  mandates**: `coverage_areas.campaign_id`, nullable. There is no
  `campaign_areas` table, and CAMPAIGNS.md says there will not be one.
- A mandate holds `boundary geometry(MultiPolygon, 4326)`,
  `accuracy_threshold_m` (default 15) and `default_h3_resolution` (default 9).
  Today a mandate is created only from an LGA in `admin_boundaries`
  (`geoverify:coverage-create --lga-code`, and `/admin/mandates`).
- `campaign_fields` is the declared data schema (key, type, options,
  required, help text). **It is displayed in the dossier, but no screen in the
  field client collects it yet.** This was open decision 3 in the Enumerate
  flow document.
- Deployment is `campaign_agent_assignments` (one live row per person per
  campaign). Commercials are admin-only, and `AssembleCampaignDossier` cannot
  reach them.

### Assignments, cells and progress

- `grid_cells` holds one H3 cell per row: `h3_index` is globally unique, with
  a boundary polygon and a centroid. `footprint_count`,
  `structures_captured` and `coverage_pct` live on the row.
- An assignment is one officer on one cell. The unique index
  `assignments_one_open_per_cell` allows one open assignment per cell.
- `RefreshCellProgress` (queued, unique per cell) recounts `structures` in the
  cell against `footprint_count`. It is pure SQL.
- `ScoreCapture` (queued) runs `ScoreObservation` over a
  `StructureObservation`. Ten signals share 100 points between them:
  - mock location
  - trace naturalness
  - network divergence
  - position accuracy
  - accuracy variance
  - satellite visibility
  - photograph provenance
  - cell containment
  - capture interval
  - implied speed

  Each signal reads assembled `CaptureFacts` and never touches the database.
  Every scoring appends to `verification_events`.
- Tracks: `field_sessions` plus `position_fixes`. Each fix records the point,
  accuracy, speed, heading, satellites, HDOP, `is_mock`, provider and a
  network point. **A walked boundary is exactly a session's fixes**, so the
  brief's `area_feature_tracks` table is not needed: the walk is a field
  session, and the anti-spoofing signals already read sessions.

### The officer client

- MapLibre GL 6 reads PMTiles through the `pmtiles://` protocol
  (`FieldMap.tsx`, `lib/mapStyle.ts`). There is **no raster basemap**: the
  pack is a vector file built by tippecanoe from footprints, cells, wards and
  roads.
  - One pack per mandate (`geoverify:pack-build`).
  - Stored in IndexedDB as chunks (`packs`, `packChunks`).
  - Abuja test pack: 20.7 MB.
- Dexie schema (`lib/offline/db.ts`, version 4): `structures`, `enterprises`,
  `photos`, `fixes`, `mutations`, `meta`, `packs`, `packChunks`, `messages`,
  `jobActions`.
- `queue.ts` holds mutations typed `entity: 'structure' | 'enterprise'`.
  `drain()` posts batches of 50 to `POST /api/field/sync`.
- `ProcessMutationBatch` works like this:
  - It sorts mutations by `client_uuid` (UUID v7, so creation order).
  - It answers replays from `sync_receipts` (`client_uuid` plus
    `payload_hash`).
  - It defers a child whose parent has not arrived.
  - Each mutation runs in its own transaction.
  - The dispatch is a single `match ($entity)`, so **a new entity is purely
    additive**: an old handset never sends it, and the two existing branches
    are untouched.
- Photographs upload on their own endpoint (`/api/field/photographs`).
  Inspections and Enumerate visits use the `jobActions` outbox
  (arrive, photo, report).
- No drawing library is installed.

### Admin, roles and surfaces

- **There is no Filament.** Admin, console and client screens are Inertia v2
  plus React 19 pages, in `resources/js/pages/{admin,console,client}`. The
  brief's "Filament panels" become React pages in those groups.
- `Role` (staff only) has `officer`, `supervisor` and `admin`. Six surfaces,
  six middleware aliases, four guards. Client admins are on the `client`
  guard against `client_users`, never in `users`.

### Officer "onboarding"

- `users` holds name, email, phone, role, status and `staff_ref`, plus
  two-factor and passkeys. Devices are separate (`devices`, revocable
  Sanctum tokens).
- **There is no officer profile model**: no languages, certifications,
  equipment or payment model.
- `identity_claims` is already polymorphic (`claimable_*`). It keeps a
  `reference_token` and `reference_last4` rather than the number, which is
  how NIN is held for businesses.

### The server

- **GDAL is not installed.** `gdal-bin` 3.12 is available on Ubuntu 26.04.
- `pmtiles` (the Go CLI) is not installed.
- 183 GB is free.
- Media goes to the local private disk, and S3 is configurable but unused.

---

## Part 2: where the brief and this codebase disagree

Each needs your call. My recommendation is given for each.

1. **Filament.** There is none. Recommendation: React pages in the existing
   groups (`/admin`, `/console`, `/client`), plus a new `/desk` surface for
   digitisers (point 5).

2. **The campaign boundary.** The brief adds a boundary column to
   `campaigns`. The project rule is that a campaign's ground *is* its mandates.
   Recommendation:
   - The campaign boundary is the union of its mandates' boundaries, computed
     and never stored twice.
   - Let a mandate be created **from an uploaded boundary file** (GeoJSON, KML
     or Shapefile), not only from an LGA. A Benue forest reserve or a project
     site is not an LGA, and this is the real gap.

3. **NIN, BVN and guarantor ID.** The brief says "encrypted at rest". The
   rule here is stricter: no raw NIN anywhere, not even encrypted.
   Recommendation: hold all three through `identity_claims` with the officer as
   claimable (token, last four and verification receipt), and never show them
   on any client surface. `AssembleCampaignDossier` already cannot reach staff
   records.

4. **Soft deletes.** Nothing here is deleted. Recommendation: no
   `deleted_at`. Withdrawn and rejected are statuses, and every change appends
   to `verification_events`. That also covers the brief's
   `area_feature_events` table without adding one.

5. **The desk_digitiser role.** Adding it to `Role` is allowed, because it is
   staff. It must answer false to `supervises()`, `administers()` and
   `capturesInTheField()`, and it gets its own surface and middleware
   (`digitises`, `/desk`). That makes seven surfaces, and CLAUDE.md is updated
   to say so.

6. **"Edited after capture, versioned."** The brief has a `schema_version`
   integer. Recommendation: an immutable `feature_class_versions` table.
   Editing a class writes a new version, and a feature points at the exact
   version it was captured against. Old exports keep their meaning.

7. **Overwrite versus observation.** The project rule is that re-enumeration
   creates a new observation and never overwrites. Recommendation: mirror
   `structures` and `structure_observations`:
   - `area_features` is the identity (class, current status, the current
     revision).
   - `area_feature_revisions` is append-only: geometry, attributes, method,
     who and when.

   A field verification of a desk feature is a new revision, so the desk
   shape and the walked shape both survive.

8. **Confidence as high, medium or low.** The building score is 0 to 100.
   Recommendation: store the number, as buildings do, and show the band
   (80 and above high, 50 to 79 medium, below 50 low). This keeps one
   scoring language across the console.

9. **S3 multipart upload.** Media is on local disk today. Recommendation:
   resumable chunked upload to the server disk now (183 GB free), with the
   storage behind the existing disk abstraction so S3 is a configuration
   change later. A 2 GB orthomosaic over a browser needs resumable upload
   either way.

10. **Third-party imagery.** Agreed: none in offline packs. Recommendation:
    none at all in version 1, online either, so nobody digitises a client's
    deliverable over imagery we have no licence to derive from.

---

## Decisions (2026-10-07)

The user asked for free satellite imagery instead of drone uploads, and for the
open questions to be settled on the side of not breaking what is built.

**Imagery comes from free satellite sources, not uploads.** All three below
were checked from this machine on 2026-10-07.

- **Sentinel-2 L2A** (ESA Copernicus), through the Earth Search STAC API on
  AWS open data:
  - No account, and cloud-optimised GeoTIFFs read over HTTP.
  - 10 m pixels.
  - Makurdi has a 0.23% cloud scene on 2025-11-19 (tile 32NMP).
  - A 6 km clip read straight from the source shows the river, both bridges,
    the road grid and green space.
  - Licence: Copernicus open data, free including commercial use, with the
    credit "Contains modified Copernicus Sentinel data".
  - Caching it offline is allowed, unlike Esri, Google or Bing.
- **ESA WorldCover 10 m 2021** (CC BY 4.0):
  - Land cover already classified: tree cover, shrubland, grassland,
    cropland, built-up, bare, water, wetland.
  - Tile N06E006 covers Benue, is readable by window over HTTP, and returns
    cropland and built-up at the expected places.
  - It is used to pre-draw land-cover polygons for officers to verify, which
    turns most desk digitising into checking.
- **OpenStreetMap** waterways and tracks become line features. ODbL: imported
  features keep their source, and a public export of them carries the ODbL
  notice.

What 10 m cannot show: individual trees, footpaths, small water points. Those
stay field captures (point and line tools). Upload of drone or commercial
GeoTIFFs stays in the design as a second source, for a client who buys
sharper imagery, and is not built first.

**The other questions, settled:**

1. **The campaign boundary** is the union of its mandates, plus mandates
   creatable from an uploaded boundary file. No column on `campaigns`.
2. **Imagery** as above.
3. **H3 resolution** stays per mandate. A mandate created for an
   area-features campaign defaults to resolution 8 (about 0.7 km²), and
   buildings keep 9.
4. **Confidence** is stored 0 to 100 and shown as high (80 and above),
   medium (50 to 79) or low.
5. **BVN and guarantor ID** are held like NIN, through `identity_claims`:
   token and last four, never the number.
6. **The 13 templates** are seeded with sensible attributes:
   - farmland: crop type, season, irrigation
   - forest: canopy density, dominant species, protected status
   - water point: type, functional, managed by

   A campaign edits its own copy.

**Stage 2 changes accordingly:**

- `BuildSatelliteBasemap` searches STAC for the least cloudy dry-season scene
  over the mandate.
- It mosaics tiles with `gdalbuildvrt` over `/vsicurl`, clips to the
  boundary, warps to EPSG:3857 and writes PMTiles. Nothing is uploaded.
- An LGA at 10 m is tens of megapixels, so packs are small. They are still cut
  per assignment zone.

**Stage 4 gains `SeedFromLandCover`:**

1. Polygonise WorldCover over the mandate (`gdal_polygonize`).
2. Map its classes to feature classes:

   | WorldCover | Feature class |
   |---|---|
   | 10 | forest_woodland |
   | 20, 30 | grassland_savanna |
   | 40 | farmland |
   | 50 | settlement_cluster |
   | 60 | bare_land_rock |
   | 80 | water_body |
   | 90 | wetland |

3. Drop pieces below the minimum mapping unit.
4. Save as `imported`, unverified, with verification tasks sampled at the
   campaign rate.

---

## Part 3: the plan

### Stage 1: schema, catalogue and campaign settings

**Built 2026-10-07; awaiting review.** The mandate-from-uploaded-boundary work
(`coverage_areas.boundary_source`) moved to Stage 2, where imagery needs the
mandate anyway; Stage 1 does not touch `coverage_areas`.

Migrations:

- **`campaigns`:**
  - `capture_modes` jsonb, default `["buildings"]`, with a check constraint
    that it contains only `buildings` and `area_features`.
  - `min_mapping_unit_ha` decimal.
  - `field_max_accuracy_m` smallint (falls back to the mandate's
    `accuracy_threshold_m`).
  - `verification_sample_pct` smallint, 0 to 100.
  - `boundary_tolerance_m` smallint.

  Existing rows default to buildings only, which is exactly today.
- **`coverage_areas`:** `boundary_source` (`admin_boundary` or `uploaded`),
  plus the uploaded-boundary path (point 2).
- **`feature_classes`:**
  - `campaign_id` (nullable, where null means a global template)
  - `key`, `label`, `geometry_type` (point, line or polygon)
  - `style` jsonb
  - `exclusivity_group` (nullable): classes in the same group may not overlap
  - `is_active`, `sort_order`
  - `copied_from_id`
- **`feature_class_versions`:** `feature_class_id`, `version`,
  `attribute_schema` jsonb and `created_by`. Immutable: an update trigger
  refuses to change one.
- **The seeder `FeatureClassTemplateSeeder`** has the 13 global templates from
  the brief: forest_woodland, individual_tree, grassland_savanna, farmland,
  river_stream, water_body, wetland, water_point, road_track_footpath,
  bare_land_rock, settlement_cluster, boundary_landmark and infrastructure.
  The land-cover polygons share one exclusivity group.

Actions:

- `CopyFeatureClassTemplates`
- `ReviseFeatureClass`, which writes a new version and never edits one
- `ValidateFeatureAttributes`, which checks against one version's schema

Screens:

- `/admin/campaigns/{id}`: a capture tab with the modes, accuracy settings
  and class catalogue (edit, reorder, deactivate).
- `/admin/feature-templates`: super admin only.
- Client dossier: a read-only list of classes.

Tests:

- Defaults leave every existing campaign building-only.
- Template copy.
- A revision creates a version, and the old version is untouchable.
- Attribute validation per type.

### Stage 2: imagery ingestion and map switcher

**Built 2026-10-07.** As decided: satellite, not uploads first.
- `BasemapLayer` per mandate. The pipeline was proved on the production server
  against Makurdi: 6 km square, 2.8 s, 112 KB, WEBP z12 to 14.
- One archive per mandate rather than per zone. At 10 m a mandate is small:
  an LGA is tens of megabytes.
- Mandates from an uploaded boundary file.
- A Street/Satellite switch with opacity and the image date. It appears on the
  capture map only when imagery is on the phone.
- An optional download row on the device page.

- **`basemap_layers`:**
  - `campaign_id`, `name`
  - `kind` (raster_pmtiles or vector_pmtiles)
  - `source` (drone, satellite or other)
  - `captured_on`, `resolution_cm`
  - bounds (geometry), `disk_path`, `size_bytes`, `licence_note`
  - `status` (uploaded, processing, ready or failed), `error`, `progress`
- **`basemap_packs`:** a layer cut per assignment zone (the cells an officer
  holds), so a phone downloads its own ground, not the whole orthomosaic.
- **Resumable upload:** chunked, with the chunk index and checksum checked on
  the server, then assembled.
- **`ProcessBasemapLayer` (queued):**
  1. `gdalinfo` to validate the CRS and read the bounds.
  2. `gdalwarp` to EPSG:3857.
  3. `gdal_translate` to MBTiles (WEBP).
  4. `gdaladdo` for the overviews.
  5. `pmtiles convert`.
  6. Write the progress and the failure reason.
- **Server work:** install `gdal-bin` and the `pmtiles` CLI, and add both to
  the runbook beside tippecanoe.
- **Phone:**
  - The raster pack is stored in IndexedDB chunks like the vector pack.
  - A layer control: street (the vector pack) or each imagery layer, with an
    opacity slider and the imagery date. The choice is remembered per
    campaign in `meta`.
  - Free storage is checked before a download (`navigator.storage.estimate`).
- **Tests:**
  - The job, with fixture GeoTIFFs: one in the right CRS, one reprojected,
    one corrupt.
  - The pack cut per zone.
  - A building-only campaign shows no switcher.

### Stage 3: phone capture tools, sync and validation

Data:

- **`area_features`:**
  - `uuid` (`client_uuid`)
  - `campaign_id`, `coverage_area_id`, `feature_class_id`
  - `current_revision_id`
  - `verification_status` (unverified, verified, rejected or
    needs_revisit), `status`
  - `confidence_score`
- **`area_feature_revisions`** (append-only):
  - `area_feature_id`, `feature_class_version_id`
  - `geom geometry(Geometry, 4326)`, constrained to Point, LineString,
    Polygon or MultiPolygon
  - `area_ha` and `length_m`, always computed in PostGIS over `geography`
  - `attributes` jsonb
  - `capture_method` (desk_digitised, field_walked, field_drawn, imported or
    field_verified)
  - `basemap_layer_id`, `imagery_date`
  - `gps_accuracy_m`, `device_id`, `field_session_id` (the walked track)
  - `captured_by`, `client_uuid`, notes
  - `geometry_repaired` (bool) and `repair_area_change_pct`

  GIST on `geom`.
- **`area_feature_cells`:** `area_feature_id` and `grid_cell_id`, written by
  SQL:
  - `h3_polygon_to_cells` for polygons
  - cells along the line, sampled with `ST_LineInterpolatePoints`, for lines
  - the containing cell for points
- **`media`:** a nullable `bearing_deg` column, and new kinds `area_photo`
  and `area_verification_photo`, attached to the revision. Additive only.

Sync:

- One new branch, `'area_feature' => ...`, in `ProcessMutationBatch::dispatch`
  calling `CaptureAreaFeature`.
- Receipts, ordering, deferral and idempotency are inherited unchanged.
- The `structure` and `enterprise` branches are not touched.
- The field client's `Mutation['entity']` type gains `'area_feature'`.

Validation (all in PostGIS, inside `CaptureAreaFeature`):

- **Geometry:** `ST_IsValid`. Otherwise `ST_MakeValid`, flagged when the area
  changes by more than a set percentage. A self-intersecting line, or an
  unrepairable shape, is rejected with the reason.
- **Size:** polygon `area_ha` must be at least the campaign's minimum mapping
  unit.
- **Placement:** inside the campaign boundary (`ST_DWithin` on geography with
  the tolerance). For field methods, also inside the officer's open
  assignment cells.
- **Overlap:** against live features of the same exclusivity group, beyond
  the tolerance. The conflicting feature uuids are returned.
- **Attributes:** checked against the class version the client captured with.
- **Area and length:** always recomputed on the server, and any client values
  are ignored.

Scoring:

- `ScoreAreaRevision` is a separate action with its own signal list. It reuses
  the existing signals that read session facts (mock location, accuracy,
  accuracy variance, satellites, implied speed), and adds:
  - `WalkSpacingRegularitySignal`: perfectly even vertex spacing.
  - `WalkJitterSignal`: zero GPS noise.
  - `TrackGeometryDivergenceSignal`: the walked track far from the drawn
    shape.
- `ScoreObservation` and its weights do not change.

Cell progress:

- `RefreshCellAreaProgress` is a new job and a new column,
  `area_features_count` on `grid_cells`.
- `RefreshCellProgress` stays exactly as it is.

Phone screens:

- A Buildings / Area features switch, shown only when the campaign has the
  mode and the officer is certified (Stage 5 provides certification; until
  then, an admin flag).
- **terra-draw** with its MapLibre adapter. It is lazy loaded on the area
  screen only, so a building officer never parses it, and it is precached by
  the service worker like every other chunk.
- Tools:
  - point: tap, or current position
  - line and polygon: undo, vertex drag, and snapping to existing vertices
    within N pixels
  - walk mode: records through the existing session and fixes. A vertex is
    dropped every N metres or on a button press, and fixes worse than the
    threshold are refused. Shows live area and length, and closes on finish.
- Class picker, then a form generated from the class version's schema, then
  photographs with bearing.

Tests:

- Pest:
  - every validation case in the brief
  - area and length against known geometries
  - schema versioning
  - sync of the same batch three times, out of order and after reassignment
  - each spoofing signal
- Playwright: offline area capture (switch to imagery, draw a polygon, walk a
  line, photos), then sync on reconnect.
- The whole existing building suite, unchanged.

### Stage 4: desk digitising and verification tasks

**Built 2026-10-08.**
- The `desk_digitiser` role, with `/desk` behind `digitises`.
- The desk map: satellite imagery, with the street pack optional.
- All-or-nothing import, with property-to-class mapping.
- `SeedFromLandCover`: WorldCover, then sieve, polygonize and simplify. Proved on the server: 30 km2 became 280 polygons in 0.7 s.
- `GenerateVerificationTasks`: a deterministic md5 sample, sent to the nearest officer.
- The phone's "Sent to check" list: confirm or reclassify as a `field_verified` revision, or "not there" / "cannot check today" via the `area_feature_outcome` sync entity.
- Found while building: the capture method had come from the payload, so a phone could claim to be the desk. The channel is now the caller's.

- **Role and surface:** a `DeskDigitiser` role, the `digitises` middleware
  and the `/desk` surface.
- **The desk map:** a full-screen map with the campaign imagery (online, from
  the same PMTiles), the class picker, the same terra-draw tools and the
  attribute form. Saves go through `CaptureAreaFeature` with
  `capture_method = desk_digitised`, so one set of rules applies.
- **Bulk import:**
  1. Upload GeoJSON, KML, a zipped Shapefile or GeoPackage.
  2. Convert server-side with `ogr2ogr` to GeoJSON lines.
  3. Map the source's attribute to a class.
  4. Preview: counts per class, invalid shapes, overlaps.
  5. Commit as `imported`, in one transaction.
- **`area_verification_tasks`:**
  - `area_feature_id`, `assigned_to`, `assigned_by`
  - `status` (open, done or cancelled)
  - `outcome`
- **`GenerateVerificationTasks`:** samples desk features at the campaign rate
  and assigns them to the nearest deployed officer, by distance from their
  assigned cells in PostGIS.
- **On the phone:** a "Verify" list and a navigate-to screen. Confirming,
  reclassifying, adding field-only attributes and photographs writes a
  `field_verified` revision through sync and closes the task.

### Stage 5: client views, exports, onboarding

**Client side built 2026-10-09.** `/client/campaigns/{id}/land`: the map by
class (filters for class, method and ground check, which the download obeys
too), the summary (`AssembleAreaSummary`), and `ExportAreaFeatures`: GeoJSON,
GeoPackage (a layer per class), Shapefile (one per class, answer columns named
to ten characters by us and recorded in the dictionary), KML and CSV with WKT,
each zipped with `data-dictionary.csv` and a README carrying the WorldCover
attribution when it applies. Every download writes `area_features.exported`
with the zip's SHA-256, by a client actor (`ACTOR_CLIENT`). The area report is
the sixth document on the print path. Nothing names the officer.
Onboarding (profiles, equipment, identity) waits for the agent application
brief, which it overlaps.

- **Client dossier:**
  - An area map by class, with filters for class, method, status, date and
    officer.
  - A summary: hectares and count per class, km of rivers and tracks, and
    verification progress against the sample target.
- **Exports:**
  - GeoJSON, GeoPackage, zipped Shapefile, KML, and CSV with WKT.
  - Each comes with a data dictionary built from the class versions.
  - Through the existing export controller and `RecordExport` audit, with
    `ogr2ogr` for the formats.
  - A PDF area report as a sixth document on the existing `PdfRenderer` path,
    not a second pipeline.
- **`officer_profiles`** (one per user): languages, `certified_modes` jsonb,
  GPS test result and date, device free storage at last check, and payment
  model (per record, day, hectare or fixed) with rate.
- **`officer_equipment`:** item, serial, issued and returned. Returned is
  stamped, never deleted.
- **NIN, BVN and guarantor ID:** through `identity_claims` (point 3).
- **Admin screen:** the profile and equipment tab on `/admin/people`.

---

## Files touched (outline)

Stage 1:

- **Migrations:** campaigns capture settings, `feature_classes`,
  `feature_class_versions`, coverage area boundary source.
- **New:** `app/Domain/Campaign/Models/{FeatureClass,FeatureClassVersion}.php`
  and actions `CopyFeatureClassTemplates`, `ReviseFeatureClass`,
  `ValidateFeatureAttributes`.
- **Seeder:** `database/seeders/FeatureClassTemplateSeeder.php`.
- **Controllers:** `Admin/CampaignController.php` (capture tab) and a new
  `Admin/FeatureTemplateController.php`.
- **Pages:** `resources/js/pages/admin/{Campaign,FeatureTemplates}.tsx` and
  `client/CampaignDossier.tsx`.
- **Docs:** `CAMPAIGNS.md` and `CLAUDE.md`.

Stage 2:

- **New domain:** `app/Domain/Imagery/` (models `BasemapLayer` and
  `BasemapPack`, the `ProcessBasemapLayer` job, `ReceiveUploadChunk`).
- **Controllers:** `Admin/BasemapController.php` and
  `Api/Field/MapPackController.php`, which gains imagery packs additively.
- **Client:** `resources/js/lib/offline/{db,pack,usePack}.ts` (Dexie version
  5, an additive store), `components/FieldMap.tsx` and `lib/mapStyle.ts`
  (raster source and layer control).
- **Docs:** `docs/deploy-hostinger.md` (GDAL, pmtiles).

Stage 3:

- **New domain:** `app/Domain/AreaCapture/` (models `AreaFeature`,
  `AreaFeatureRevision`; actions `CaptureAreaFeature`, `ValidateAreaGeometry`,
  `ScoreAreaRevision`; the new signals).
- **Jobs:** `app/Jobs/{ScoreAreaFeature,RefreshCellAreaProgress}.php`.
- **Sync:** `app/Domain/Sync/Actions/ProcessMutationBatch.php`, one added
  match arm only.
- **Migrations:** the `media` bearing column and kinds.
- **Client:** `resources/js/pages/field/AreaCapture.tsx` (new),
  `lib/offline/queue.ts` (the entity type), `lib/offline/db.ts`, and
  `package.json` (terra-draw).

Stage 4:

- **Role:** `app/Enums/Role.php`, plus the middleware alias in
  `bootstrap/app.php`.
- **Pages and controllers:** `resources/js/pages/desk/*`,
  `app/Http/Controllers/Desk/*`.
- **New actions:** `ImportAreaFeatures`, `GenerateVerificationTasks`.
- **Client:** `resources/js/pages/field/Verify.tsx`.

Stage 5:

- **Client surface:** `AssembleCampaignDossier` (area summary, no
  commercials), `client/CampaignDossier.tsx`.
- **Exports:** `app/Domain/Verification/Exports/AreaFeature*`.
- **Officer profile:** `app/Domain/Staff/Models/{OfficerProfile,OfficerEquipment}`,
  `admin/People.tsx`.

---

## Questions before Stage 1

1. **The campaign boundary.** Agree it is the union of mandates, with
   mandates creatable from an uploaded boundary file (Part 2, point 2)?
2. **Benue imagery.** Is there drone or satellite imagery already? Roughly
   how many hectares, at what resolution, and how large are the files? This
   sets Stage 2's storage and the per-zone pack size phones can hold.
3. **The cell size for land.** H3 resolution 9 cells are about 0.1 km². For
   large forest blocks, resolution 8 (about 0.7 km²) or 7 may suit
   assignments better. It is per mandate already, so this is a choice, not a
   build.
4. **Confidence** as a stored number shown as high, medium or low
   (Part 2, point 8)?
5. **BVN and guarantor ID** held like NIN (token and last four, never the
   number), or is there a reason the full number must be kept?
6. **The first campaign's classes.** Are the 13 templates right, and are
   there Benue-specific attributes you already know of (crop type, tree
   species, ownership, water point functionality)?
