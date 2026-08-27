# Phase 2 · M0 survey

What is already in this codebase, what Phase 2 can reuse, what it must extend,
and where Phase 1 made a decision that makes Phase 2 harder.

Read against `main` at `4a1fa45`. 206 tests passing, Larastan level 6 clean.

---

## 1. Correction to the brief before anything else

**There is no identity verifier driver interface, and no `NullVerifier`.** Section 0
asks me to read them; they were never built. What exists is:

- `identity_claims` with `kind`, `reference_token`, `reference_last4`, `status`
  (`declared` / `pending` / `verified` / `mismatch` / `failed`), `verifier` (a
  string name), `verified_at`, `response_ref`, `raw_payload` (encrypted).
- `HashIdentityReference`, which keys-hashes NIN and BVN and passes CAC and TIN
  through in full.
- `IdentityClaim::PERSONAL_KINDS = [nin, bvn]`, the list that drives hashing.

So the storage rule is enforced and the schema is right. **The dispatch layer is
missing entirely.** Nothing calls out to a channel, and nothing decides whether a
claim is verifiable. Phase 2 must build the driver contract, not extend one.

This is good news for the NIN rule: there is no code path that could leak a
number, because there is no code path at all. It is bad news for M0 estimates
that assumed a driver existed.

---

## 2. Reuse as-is

These need no change and Phase 2 should call them directly.

| Thing | Where | Why it fits |
|---|---|---|
| `ResolveAdminHierarchy::forPoint()` | `Domain/Registry/Actions` | `ST_Contains` ward/LGA/state resolution from a point. Exactly what §5 requires, already server-side, already never trusting a client. |
| `DetectDuplicateEnterprise::near()` | `Domain/Registry/Actions` | `ST_DWithin` + `similarity()` trigram on trading name. §5's duplicate-at-entry check is this query with a different caller. |
| `SearchSectors` | `Domain/Registry/Actions` | ISIC + Nigerian trade aliases with a trigram index. The self-registration sector picker is the field picker with a different skin. |
| `HashIdentityReference` | `Domain/Identity/Actions` | The NIN rule in code. Phase 2 calls it and never reimplements it. |
| `PdfRenderer` | `Domain/Verification/Exports` | Headless Chromium, discovers its own binary, explains its own concurrency requirement. The §7 report is a second Blade view through the same renderer. |
| `VerificationEvent::record()` | `Domain/Verification/Models` | Append-only, DB-trigger-protected against update and delete. Every Phase 2 transition goes here. |
| `Media` + private disk + signed URLs | `Domain/Media` | Documents and report PDFs live here. |
| The review-queue pattern | `BuildReviewQueue`, `AssembleReviewRecord`, `ReviewObservation` | §4 says do not build a second reviewing UI. This is the one to extend. |
| Design tokens + `StatusPill` + `status.ts` | `resources/css/app.css`, `resources/js/lib/status.ts` | Five tones, each with a fixed hue **and** a fixed shape. Portal inherits the vocabulary unchanged. |

---

## 3. Extend, carefully

### 3.1 `assignments` cannot express a paid verification visit

Two blockers, both structural.

```sql
CREATE UNIQUE INDEX assignments_one_open_per_cell
  ON assignments (grid_cell_id) WHERE closed_at IS NULL
```

A cell may hold **one** open assignment. A paid order against a cell an officer
is already sweeping cannot create its assignment at all: the insert violates the
index. This is not a rare edge — a mandate sweep and a paid order in the same
ward is the normal commercial case.

Second, `assignments` is **cell-scoped**. There is no `structure_id`. A
verification visit targets one building, not 626.

**Proposed extension, once, with both paths using it:**

- Add `kind` (`sweep` | `verification_visit`), default `sweep`.
- Add nullable `structure_id`, constrained, meaningful only for
  `verification_visit`.
- Add `priority` (smallint, default 0).
- Replace the index with a partial one scoped to sweeps:
  `... WHERE closed_at IS NULL AND kind = 'sweep'`.
  Sweeps keep their exclusivity exactly as today; visits are unconstrained
  because several paid visits in one cell is a good problem.

The field client, the sync contract and `AssignCells` keep calling the same
behaviour. Nothing in the shipped officer app changes.

### 3.2 `users` is a staff table and cannot hold parties

```
users: email NOT NULL UNIQUE, password NOT NULL, role ∈ {officer, supervisor, admin}
```

Phase 2 needs phone-first, password-optional, self-registering accounts. Three
things block reusing `users` directly: `email` is `NOT NULL UNIQUE` (a trader
with no email cannot be inserted), `password` is `NOT NULL`, and `Role` is a
closed staff enum whose `supervises()` and `capturesInTheField()` are read all
over the field platform.

**Recommendation: a separate `party_users` identity, not a fourth role.**
Widening `Role` puts a shop owner one enum case away from `supervises()`, and
every existing `$user->supervises()` call becomes a place that could be wrong.
The blast radius of a mistake is the supervisor console.

Details in the build plan (M2). The short version: parties authenticate on
their own guard; `users` stays exactly what it is.

### 3.3 `enterprises` has no publication state and no party

Needs `publication_state` (`private` default | `opted_in` | `withheld`) and the
`party_businesses` link table. §8's rule is enforced by a global scope plus a
test that a `private` record cannot leave any endpoint without an authenticated
mandate context.

### 3.4 `consent_records.recorded_by` is `NOT NULL` to `users`

Consent was designed for an officer recording it at a doorstep. A party granting
publication consent from their own dashboard has no staff user. Make it
nullable and add a party actor. The column stays for field-captured consent.

### 3.5 `StorePhotograph::store()` requires `User $officer`

A party uploading a CAC certificate is not an officer. Either generalise the
signature to an actor union or extract the hashing / EXIF / storage core and let
both callers use it. **Extract, do not refactor the field caller's behaviour.**

### 3.6 `VerificationEvent` actors

`record()` takes `?User $actor` and an `actor_type` of `user` | `system` |
`external`. `actor_label` is a free string. A party action can be recorded today
as `external` with a label, but that flattens "a party did this" into the same
bucket as "a third-party API did this". Add an `ACTOR_PARTY` constant and set
`actor_id` to the party. No schema change needed — `actor_id` is a plain
nullable integer, not a foreign key.

---

## 4. Phase 1 decisions that make Phase 2 harder

Ordered by how much they cost.

**1. `assignments_one_open_per_cell`.** Described above. It was the right call
for a sweep-only world and it is now the single biggest structural obstacle.
Cost: one migration, one index swap, and care that `AssignCells` still behaves.

**2. Enumeration recorded no contactable owner on `enterprises`.** Phone and
email live on `enterprise_observations`, not on the enterprise. The
phone-match claim path — the strongest and cheapest signal we have — must read
the latest observation per enterprise. Workable, but every claim search and
every OTP dispatch is a lateral join rather than a column read. Worth an index.

**3. `CLAUDE.md` forbids exactly this phase.** Two rules:

> - **Business Portal, Discovery Portal and Command Centre do not exist here.**
> - **Nobody self registers.** Fortify's registration feature stays off.

Both are load-bearing in the file that overrides default behaviour, and both
are contradicted by this brief. They must be reopened deliberately, in a commit
whose message says so, or the repo's own constraints file argues against its
code. The Discovery Portal and Command Centre exclusions stay.

**4. No scheduler.** `routes/console.php` schedules nothing. The retention
command in §8 needs the scheduler wired from zero.

**5. `structures.status` has no `unconfirmed`.** §5 wants a provisional
structure a field officer later resolves. Add the state; the existing constants
are `draft | submitted | accepted | flagged | rejected`.

**6. The bundle is already split, and that helps.** M8 made Inertia resolve
pages lazily, so the portal's pages will not load the console's or the field
app's. Nothing to do; worth knowing the ceiling is not being paid twice.

---

## 5. Things in the brief that the code already answers

- **"Every state change appends to `verification_events`"** — enforced by a
  database trigger that blocks updates and deletes, not by convention.
- **"Every new spatial operation goes in PostGIS"** — the whole Phase 1 codebase
  holds this line, including SVG path generation for the evidence pack.
- **"Reuse the Phase 1 evidence-pack renderer"** — `PdfRenderer` is already
  generic: it prints a URL to a PDF. The report is a new Blade view and a new
  signed route, not a new pipeline.
- **"Nothing is hard-deleted"** — holds; `ReleaseAssignment` closes rather than
  deletes, observations append.

---

## 6. Open commercial questions

Both are decisions rather than engineering, and both shape schema.

**Claim evidence thresholds.** §4 lists five signals but not the bar. I need to
know which combinations auto-approve. My proposal, to be accepted or corrected:

| Evidence | Outcome |
|---|---|
| OTP to the phone captured at enumeration | Auto-approve |
| CAC verified **and** claimant identity verified **and** claimant on the returned director roster | Auto-approve |
| CAC verified, claimant identity verified, claimant **not** on the roster | Review |
| Documents only | Review |
| Location proximity only | Never sufficient — supporting evidence only |
| Any signal, but the listing is already claimed | Dispute, never auto-approve |

Open sub-question: does auto-approval differ by tier being claimed, or is
control of a listing a single decision regardless of tier?

**Fee and SLA structure.** §7 needs `fee_amount`, `urgency` and `sla_days` at
order creation. I need the actual ladder: price points per tier, which urgency
tiers exist, the SLA days each commits to, and the refund window. Also whether
price varies by geography (a visit in a dense AMAC ward and one 40 km out are
not the same cost) and whether `monitored` is priced as a subscription or as
repeated orders, because that changes whether the ledger needs recurring
billing in M6 or not at all.

I would rather settle both now than discover the shape after M3 and M6 are built
around a guess.
