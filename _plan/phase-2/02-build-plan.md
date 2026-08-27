# Phase 2 · build plan

Milestone order, migrations, routes, and what runs at the end of each one.

Every milestone ends with a gate that is a demonstrable behaviour, not a
checklist. Same standard as Phase 1: `php artisan test`, Larastan level 6, Pint,
`tsc --noEmit`, ESLint, all clean before a milestone is called done.

---

## M0 — Survey · this document set

Gate: the three documents, approved. **No application code.**

---

## M1 — Design extension

Portal surface added to `/design`, screenshotted, shown before any feature work.

**Builds**
- `--gv-paper-edge` and `--gv-held` tokens, daylight only.
- `display-xl` type step.
- `held` status tone: a sixth entry in `status.ts` with its own shape.
- `VerificationLadder` component: five rungs, three densities (compact, full,
  print), all five rung states.
- `MoneyPanel`: the fee / SLA / queue / conditions block as one component, so no
  screen can render a fee without its conditions.
- Portal layout primitives: page shell, section head, fact row, document frame.

**Migrations** none. **Routes** none.

**Gate:** `/design` shows the ladder in all five states at all three densities
and the money panel, at 360px, in daylight, and I show you the screenshots.

---

## M2 — Parties and access

**Migrations**
- `parties` — `id, code (unique), kind, display_name, legal_name, primary_phone,
  primary_email, country, status, identity_tier, created_at`.
- `party_users` — `id, party_id, user_id, role (owner|manager|viewer),
  invited_by, accepted_at`.
- `party_credentials` — the phone-first auth record: `party_user_id, phone
  (unique), password (nullable), webauthn credentials, last_signed_in_at`.
- `add_actor_party_to_verification_events` — none needed; `actor_id` is a plain
  nullable integer. Adds `ACTOR_PARTY` as a constant only.

**The `users` question, settled.** Parties do not become a fourth `Role`.
`Role::supervises()` and `Role::capturesInTheField()` are read throughout the
field platform, and adding a case puts a shop owner one enum value away from the
supervisor console. Parties authenticate on their own guard against
`party_users`, and `users` stays the staff table it is.

**Party code.** `NBD-XXXX-XXXX-C`, uppercase alphanumeric excluding `0 O 1 I L`,
ISO 7064 Mod 37,36 check character. Encodes nothing: not a sequence, not a date,
not a location. Tests prove a single-character error fails, a transposition of
adjacent characters fails, and that codes are not guessable in sequence.

**Routes** `/portal/register`, `/portal/sign-in`, `/portal/verify-code`,
`/portal` (dashboard), `/portal/team`.

**Gate:** a person registers with a phone number, receives a code, signs in, and
reaches an empty dashboard. **Measured:** landing to dashboard for a returning
user, reported as a number. Every sign-in appears in `verification_events`.

---

## M3 — Claim flow · the milestone that matters most

Given the most time. This is the flow that converts the Phase 1 dataset into a
user base.

**Migrations**
- `claims` — `party_id, enterprise_id, relationship, asserted_at, evidence
  (jsonb), decision, decided_by, decided_at, status`.
- `claim_disputes` — `claim_id, challenger_party_id, opened_at, resolution,
  resolved_by, resolved_at`.
- `party_businesses` — `party_id, enterprise_id, relationship, established_via,
  established_at, status`.
- Index on `enterprise_observations (enterprise_id, observed_at desc)` so the
  latest-phone lookup is not a scan.

**Extends** the console review queue rather than building a second one:
`BuildReviewQueue` gains a claim source, `ReviewObservation`'s decision pattern
is mirrored by a `DecideClaim` action. Same screen, same three-question
discipline where it applies.

**Search** is over the private register: trigram on trading name plus
`ST_DWithin` proximity, both already available. Results expose trading name,
ward, LGA, structure type and tier, and nothing else.

**Routes** `/portal/claim`, `/portal/claim/{enterprise}`,
`/portal/claim/{claim}/prove`, `/console/claims` (review, inside the existing
console group).

**Gate:** a claimant finds their enumerated shop, proves control by OTP to the
number the officer captured, and manages the listing. A second claimant on the
same listing opens a dispute with a stated resolution path. A claimant cannot
edit or delete the officer's observation — proven by test, not by convention.

---

## M4 — Self-registration

**Migrations**
- `structures.status` gains `unconfirmed`.
- `structures.origin` — `field | self_registered`, so a provisional record is
  never mistaken for a captured one.

**Reuses** `ResolveAdminHierarchy::forPoint()` and
`DetectDuplicateEnterprise::near()` unchanged.

**Routes** `/portal/register-business` (multi-step, saves progress).

**Gate:** a business not in the register self-registers with a mandatory
location, either selecting a detected footprint or dropping a pin that creates
an `unconfirmed` structure. Ward, LGA and state are resolved server-side and a
client-supplied hierarchy is rejected. A near-duplicate offers the claim flow
instead of creating a second record. Completes in one sitting on a throttled 3G
profile, and I report the page weight.

---

## M5 — Listings and corrections

**Migrations**
- `correction_proposals` — `party_id, enterprise_id, field, proposed_value,
  reason, status, reviewed_by, reviewed_at`. The original observation is never
  touched.
- `enterprises.publication_state` — `private` default, `opted_in`, `withheld`.
- `consent_records.recorded_by` becomes nullable; adds `recorded_by_party_id`.
- Document storage reuses `media`; `StorePhotograph`'s core is extracted so a
  party can upload without being an officer.

**Gate:** a party proposes a correction, a supervisor reviews it, the original
observation survives alongside the accepted proposal, and the tier display shows
a date and a freshness state driven by a configurable window.

---

## M6 — Verification marketplace

**Migrations**
- `verification_orders` — as specified in the brief.
- `ledger_accounts`, `ledger_entries` — double-entry, append-only, nothing
  adjusted in place.
- `payment_webhook_events` — signature-verified, idempotent by provider event id.
- `assignments` extension: `kind`, nullable `structure_id`, `priority`, and the
  partial unique index rewritten to `WHERE closed_at IS NULL AND kind = 'sweep'`.

**The one extension to the field platform**, made once, with both paths using
it. `AssignCells` keeps its behaviour; a new `AssignVerificationVisit` creates
the targeted assignment. The officer's capture flow, the sync contract and the
shipped client are untouched.

**Gate:** an order is placed and paid, funds are held in our ledger, an
assignment appears in the supervisor console's existing view flagged priority,
an officer completes it through the existing capture flow, supervisor acceptance
transitions the order to completed, and the ledger balances. A replayed webhook
changes nothing. A client-side callback changes nothing. The word *escrow*
appears nowhere, including in state names — proven by a test that greps the
codebase.

---

## M7 — Reports, certificates, consent

**Migrations**
- `processing_purposes` — lawful basis per purpose, referenced by the code paths
  that process.
- `consent_receipts` — what was agreed, when, by whom, downloadable.
- `public_verifications` — the token behind the QR URL.

**Reuses** `PdfRenderer` and the evidence-pack pattern: a second Blade view and
a second signed loopback route, not a second pipeline.

**Gate:** a completed order produces a PDF with finding, coordinates and
accuracy, hierarchy, date, photographs, an officer reference that is not the
officer's identity, and a QR resolving to a public verification URL. Publication
opt-in and revocation both append to `verification_events`, and **revocation
takes effect immediately** — proven by test. A `private` record cannot be
returned by any endpoint without an authenticated mandate context — proven by
test.

---

## M8 — Hardening

**Gate, all measured and reported as numbers:**
- Failure-path Pest suite: webhook replay, payment abandoned mid-flow, SLA
  breach refund, claim disputed after approval, consent revoked while an order
  is in flight.
- `geoverify:reconcile-ledger` compares our ledger to Paystack's settlement
  report and reports drift.
- Full registration and order flow on a throttled 3G profile, timed.
- Load test on claim search.

---

## Cross-cutting, from M2 onward

- Every state change appends to `verification_events`. Nothing is hard-deleted.
- Every spatial operation in PostGIS.
- No raw NIN anywhere: no column, no log line, no cache entry, no queue payload,
  no exception message. The queue payload rule is new in this phase and worth a
  test, because an order carries identity context through a job.
- Client-supplied ward, LGA or state never trusted.
- Nothing from the Discovery Portal or the Command Centre.

## The `CLAUDE.md` change, made once and deliberately

Two Phase 1 rules contradict this brief and must be reopened in a commit that
says so:

- *Business Portal … do not exist here* → narrowed to the Discovery Portal and
  the Command Centre, which stay excluded.
- *Nobody self registers* → narrowed to staff. Officers, supervisors and admins
  are still created by an admin; parties self-register by design.

Proposed as the first commit of M1, before any portal code, so the constraints
file never disagrees with the repository.
