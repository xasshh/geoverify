# Campaigns

The layer above field enumeration. A **campaign** is a commissioned exercise: why
ground is being walked, for whom, against what target and to what timetable. A
**mandate** (`coverage_areas`) is the ground itself, and it is unchanged from
Phase 1: it still owns its H3 grid, its offline map packs, its assignments and
every structure captured inside it.

One client holds many campaigns over time. One campaign holds many mandates.
Nothing in the field platform reads the campaign tables.

## The tables

| Table | What it holds |
|---|---|
| `client_organisations` | The commissioning body. `short_code` drives the campaign code. |
| `client_users` | Their administrators, on the `client` guard. |
| `campaigns` | Code, name, subject, brief, objective, status, dates, target. |
| `campaign_commercials` | Contract value, payment state, internal notes. One row per campaign, reached from one method. |
| `campaign_fields` | The declared data schema. |
| `campaign_stakeholders` | Who has to be engaged, and whether the client sees them. |
| `campaign_agent_assignments` | Who is deployed on the exercise. |
| `campaign_user_acknowledgements` | Who has read the brief, polymorphic across guards. |
| `coverage_areas` | Gains nullable `campaign_id` and `target_record_count`. |

There is no `campaign_areas` table. A campaign's areas **are** its mandates. A
parallel table would have left two notions of area, one with a boundary, a grid
and offline tiles and one with a state name and a target, and the offline pack
would still have been built from the first.

## Status lifecycle

```
draft ─────────► pending_approval ─────────► approved ─────────► active
  │                    │  │                     │  │                │
  │                    │  └──► draft            │  └──► draft       ├──► paused ──┐
  │                    │                        │                   │      │      │
  └────────────────────┴────────────────────────┴───────────────────┴──────┴──► completed
                                                                                  │
                                                                                  ▼
                                                                             archived
```

Every move is a named act with a guard, never a status dropdown. Activating puts
officers on the road and starts a clock a client is holding us to.

| From | May become |
|---|---|
| `draft` | `pending_approval`, `archived` |
| `pending_approval` | `approved`, `draft`, `archived` |
| `approved` | `active`, `draft`, `archived` |
| `active` | `paused`, `completed` |
| `paused` | `active`, `completed`, `archived` |
| `completed` | `archived` |
| `archived` | nothing |

Two extra guards on activation, in `TransitionCampaign`:

- **Not approved, not active.** `approved_at` must be set. The lifecycle already
  forbids `draft → active`; this catches a campaign whose approval was cleared.
- **Not before its start date, without saying so.** Activating ahead of
  `starts_on` is sometimes right and never accidental, so it takes an explicit
  override rather than being silently allowed or flatly refused.

Every transition appends to `verification_events` as `campaign.{status}`, with
what it moved from and any note. A client asking why an exercise paused for three
weeks in October is asking a question the log already answers.

No soft deletes. `archived` is the terminal state, consistent with the rule that
nothing in this system is hard deleted and status changes append.

## Visibility matrix

| | Super Admin | Client Admin | Supervisor | Field Agent |
|---|---|---|---|---|
| Campaign list | all clients | own org, non-draft | all | deployed only |
| Definition, brief, objective | read/write | read | read | read |
| Scope, coverage, map | read/write | read | read | read |
| Data schema | read/write | read | read | read |
| Timeline, target, progress | read/write | read | read | read |
| Agent roster | read/write | read | read/write | read |
| Stakeholders `visible_to_client = true` | read/write | read | read | read |
| Stakeholders `visible_to_client = false` | read/write | **never** | read | read |
| Contract value, currency | read/write | **never** | **never** | **never** |
| Payment status, paid date | read/write | **never** | **never** | **never** |
| Internal notes | read/write | **never** | **never** | **never** |
| Status transitions | yes | no | no | no |

Draft and `pending_approval` campaigns are invisible to the client whose campaign
they will become. A campaign being written or priced is our working document.

## How the commercial rule is actually enforced

Three layers, and the first is the one that matters:

1. **`AssembleCampaignDossier` has no code path to `campaign_commercials`.** Not
   conditionally, not behind a flag. It is the action behind every client screen,
   so a client payload cannot carry a contract value however carelessly it is
   assembled downstream. The only method in the codebase that reads commercials
   for a screen is `Admin\CampaignController::commercialsFor()`.
2. **A separate table.** Columns on `campaigns` would ride along in every model
   instance a client screen touched. One forgotten `$hidden`, one `toArray()`,
   one prop spread.
3. **`CampaignPolicy::viewCommercials()`**, named separately from `view()` so
   that "can this person see the campaign" and "can this person see what it is
   worth" can never become the same question by accident.

Tested by walking the whole serialised HTTP response for a client session, not by
checking the keys somebody thought of on the day.

## Scoping

Both, not either:

- **Query scopes** keep another organisation's rows out of a result set:
  `Campaign::visibleToClient($orgId)`, `Campaign::deployedTo($userId)`,
  `CampaignStakeholder::visibleToClient()`.
- **The policy** makes a direct hit on somebody else's id a 403 rather than a
  blank page that leaves you wondering whether the record exists.

Internal stakeholders are excluded in SQL, never filtered after loading. A
contact we are keeping to ourselves must not be in the result set at all.

## Guards

Three, and a session on one can never satisfy the middleware of another.

| Guard | Table | Middleware | Surface |
|---|---|---|---|
| `web` | `users` | `field`, `supervises`, `administers` | `/field`, `/console`, `/admin` |
| `portal` | `portal_accounts` | `portal` | `/portal` |
| `client` | `client_users` | `client` | `/client` |

A client administrator is not staff. `users` is the staff table and `Role` is
never widened to admit somebody who does not work here, because
`Role::supervises()` is read all through the field platform.

## Acknowledgements

The intro modal shows once per person per version of the brief. A person who has
read what was commissioned should not be shown it every Monday.

Acknowledgements are **never deleted**. A revision stamps
`campaigns.definition_revised_at`, and an acknowledgement older than that stops
counting. Clearing rows would answer "has this person seen it" at the cost of
"did they ever", and the second is the question an auditor asks.

The comparison is strictly after, not at or after: these columns hold whole
seconds, and of the two possible mistakes, showing a brief once too often is
cheaper than not showing a revised one.

A material revision is **ticked by the person making the change**, not inferred.
Re-showing a modal because somebody fixed a typo teaches everybody to dismiss it
without reading.

## Dates

`config('app.timezone')` is `Africa/Lagos`. Every campaign date calculation goes
through it and compares at the start of the day, so "days remaining" does not
step early for somebody loading the page late at night.

## Campaign codes

`NRS-MIN-2026-01`: client short code, three letters of the subject, year, and the
sequence within that client and year. Unlike a party code it is meant to be read
aloud and recognised, and it encodes only what is already on the invoice.

## What is not built

`campaign_fields` is a **declaration**, not yet a collector. Phase 1 gathers
against a fixed, typed schema, with a hand-built capture screen, a typed offline
store and a sync contract that validates against enums on both the live and the
offline path. Making the field client render arbitrary fields touches every one
of those, and it is a separate decision taken with the sync contract open.

What this buys today is that a client can see, exactly and in writing, the schema
they commissioned, and that a super admin states it before an officer deploys.

## Seeding

```bash
php artisan db:seed --class=CampaignSeeder
```

One client (NRS), two campaigns: `NRS-MIN-2026-01` active, `NRS-HAI-2026-01`
completed. Eleven schema fields, six stakeholders including one deliberately
internal, four officers deployed, both commercials populated. Existing mandates
are backfilled onto the active campaign so nothing orphans.

Client sign in: `client@nrs.test` / `password`.
