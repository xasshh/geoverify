# Phase 3 · survey

The Discovery Portal, opened to the public, and identity verification sold as a
product. Written from the brief given on 2026-09-09, before any code.

Phase 2 built a portal for people who already own a business on the register.
This phase opens the front door to everybody else: somebody who wants to check a
shop before buying from it, and somebody whose business is not on the register at
all. That is a different product with a different audience, and most of the work
below is new surface rather than a change to what exists.

---

## 1. What was asked

Sixteen things, kept in the order they were said so nothing is lost.

| # | Asked for | Shape |
|---|---|---|
| 1 | Sign-in A and Dashboard A | Build the approved mockups |
| 2 | Owners submit CAC, NIN or TIN when they create an account | Extends registration |
| 3 | Anybody can browse every business, like a shopping site | New public surface |
| 4 | Monthly business reviews, sectors that are booming | Editorial and aggregates |
| 5 | Only verified businesses are published | Changes a rule, see section 3 |
| 6 | Free for the first six months after launch | Pricing |
| 7 | Search CAC so any business is findable, on our register or not | Third party data |
| 8 | A business not on our register shows as present but not verified | Projection |
| 9 | Paid identity checks: BVN, NIMC, NIN, CAC, TIN, sold in bundles from about 500 naira | See section 4 |
| 10 | Reach out to businesses people search for and ask why they are not onboard | Growth loop |
| 11 | Help a business register for CAC when it has not | Guided process |
| 12 | Show a business what tax and compliance it owes, by type | Content, possibly API |
| 13 | A business chooses where its listing is published | Publication scope |
| 14 | Owners update their own information | Changes a rule, see section 3 |
| 15 | Owners add photographs and video of the business | Changes a rule, see section 3 |
| 16 | Searchers sign in with email; unfound businesses suggest similar ones | Accounts and search |

---

## 2. What already exists

More than it looks. The register was built so this phase would be possible.

- **Identity references.** `IdentityClaim` already carries `cac`, `tin`, `nin`,
  `bvn` and `cert_of_incorporation`, already separates `PERSONAL_KINDS`
  (`nin`, `bvn`) from the business ones, already hashes with
  `HashIdentityReference`, and is polymorphic, so a claim hangs off a party or an
  enterprise without a new table. Item 2 is mostly wiring.
- **The consumer account.** `portal_accounts` already has `email` and `password`
  columns beside the phone. A searcher is a portal account with no party
  membership, so item 16 needs no fourth guard and no new table. See section 5.
- **Money.** `verification_orders`, the ledger, the signed webhook and
  `PostTransaction` all exist and are proven. A bundle of identity checks is a
  new product on the same rails, not a second payments system.
- **The sector taxonomy.** 419 ISIC classes and 95 trade aliases are loaded, which
  is what makes item 4 (sectors that are booming) answerable at all, and item 12
  (obligations by business type) attachable to something stable.
- **Publication consent.** `publication_state`, `SetPublicationState`,
  `ConsentReceipt` and the receipt document already exist, so item 13 extends a
  decision the register already records rather than inventing one.
- **The reduced projection.** `SearchRegister` already returns a deliberately
  thin row for people who have proved nothing. The public directory is that
  projection widened by consent, not a new query written from scratch.

---

## 3. Rules this contradicts

Four, each written deliberately in `CLAUDE.md` with reasoning. None of them
should be quietly overwritten. Each needs a decision recorded the way the
Phase 2 rule changes were.

### 3.1 "Only verified businesses will be published" (item 5)

The current rule is narrower and was argued for at length: an unclaimed record
may appear in reduced form (trading name, sector, ward, LGA) and only when the
latest observation recorded `signage_observed`, on the reasoning that a business
which put its name on the street has published that much itself. Only a claimed
and opted-in listing is indexable.

The brief wants a directory where everything is visible, and separately wants
only verified businesses published. Those pull in opposite directions. The
resolution that keeps both: **three depths, not two.**

1. **Reduced.** Unclaimed, signage observed. Name, sector, ward, LGA. Not
   indexable. This is what makes the directory feel full on day one.
2. **Claimed.** The owner has proved control and opted in. Adds opening hours,
   description, contact the owner chose to publish, photographs the owner
   uploaded. Indexable.
3. **Verified.** An officer attended, or an identity check passed. Adds the
   verification badge, the tier, the date and the certificate anybody can check.

"Only verified businesses are published" then means: only depth 3 carries the
badge and the claim of verification. Depths 1 and 2 are present and honestly
labelled as unverified. That is item 8 exactly.

### 3.2 "Owners update their own information" (item 14)

The current rule is that a party proposes and a supervisor rules, because
re-enumeration must never overwrite what an officer recorded. That rule protects
the officer's account of a visit. It should not protect a shop's opening hours.

The split to make: **officer-established fields stay proposal-only, and a new
party-authored profile is owned outright by the party.**

- Officer-established, proposal-only: position, structure type, signage observed,
  the trading name as recorded, the phone recorded at the door, operating status.
- Party-authored, edited freely: description, opening hours the owner publishes,
  contact the owner publishes, categories, photographs, video, delivery and
  payment options, social links.

Two sources, both visible, never merged into one editable blob. A profile field
and an observation field may hold the same kind of thing (opening hours) and they
are still different facts: one is what an officer saw, one is what the owner
says.

### 3.3 "Never a photograph" (item 15)

The rule bans photographs from every public projection whatever the publication
state, and it is right about the photographs it was written for: officer
evidence, which shows interiors, neighbours, passers-by and sometimes people who
never consented to anything.

Party-uploaded marketing media is a different object with a different consent. It
must be a **separate collection** from `media`, published only by the party's own
act, and the two must never share a query. If they share a table, somebody
eventually publishes evidence by accident, and the rule exists precisely because
that failure is unrecoverable.

### 3.4 "The Discovery Portal does not exist here"

Already lifted on 2026-09-07 when the public marketplace was commissioned. This
phase is the build. The Command Centre stays excluded.

---

## 4. The one thing that cannot be built as described

Item 9 asks that any visitor pay about 500 naira to run a **BVN, NIN or NIMC**
check on a business they are curious about. That cannot be built, and the reason
is not squeamishness.

- **NIN and NIMC.** Verification of a National Identity Number is a check on a
  living person. Under the NDPA 2023 that processing needs a lawful basis, and
  for a stranger's curiosity there is not one. NIMC's own terms bind licensed
  verification agents to consented use.
- **BVN.** Bank Verification Number sits under CBN rules. Access is confined to
  licensed financial institutions and their appointed agents, for KYC on their
  own customer. A public directory selling BVN lookups to anybody with 500 naira
  is not a grey area.
- **CAC and TIN.** Different in kind. These identify a **company**, and company
  registration is a public register. Confirming that a business exists, its RC
  number, its status and its registered address is ordinary due diligence and is
  the thing a buyer actually wants.

The product survives intact with one change of direction, and is arguably better
for it:

> **A searcher pays to find out whether a business is verified, and to make it
> get verified. A business owner is the only person who can verify their own
> identity.**

Which gives:

- Search returns CAC and TIN facts for any business, ours or not. That is item 7
  and item 8, unchanged and lawful.
- Where a business is not verified, the searcher may **commission** verification.
  That either sends the business an invitation to verify itself (which is item 10,
  the outreach loop, arriving for free) or buys a physical visit, which is the
  product that already exists and is the one thing nobody can fake.
- Owners verify their own NIN, BVN, CAC or TIN with their own consent, and the
  result becomes a badge the whole directory can see. That is item 2, and it is
  the only shape in which personal identity checks are lawful.

The bundle economics still work. The searcher pays for company data and for
verification they commission; the owner pays to prove themselves once.

---

## 5. Who the new users are

A searcher is not a party. They own no business, hold no listing and can never
satisfy `supervises`. The instinct is a fourth guard, and it is wrong here: a
searcher is a **portal account with no party membership**, which the schema
already permits, and `ActingParty` already returns null for. The `portal` guard
stays one guard.

- Browsing needs no account at all. An open directory that demands a sign-up to
  look at a shop is not an open directory.
- An account is needed to buy anything, to save a business, or to contact an
  owner, so accounts are asked for at the point of value and not before.
- A searcher who later claims a business becomes a party on the same account.
  Nobody re-registers.

Email and password are the searcher's channel because they have no business phone
on any record to prove. Phone stays the owner's channel because it is the one an
officer already captured.

---

## 6. Sequencing

Four milestones. The first is unblocked and is being built now; the rest depend
on the decisions in sections 3 and 4.

**P1. The approved portal.** Sign-in A and Dashboard A built for real, plus the
listing. Adds shadcn/ui primitives, `motion` and charts, mapped onto the existing
tokens. No new data model. This is the visual foundation everything else lands
on.

**P2. The public directory.** The three depths from 3.1, open browsing, search,
sector pages, the "present but not verified" projection, similar-business
suggestions. Reuses `SearchRegister`'s discipline. Needs decision 3.1.

**P3. Owner identity and profile.** CAC, TIN, NIN and BVN submitted by the owner
with consent and a receipt. The party-authored profile from 3.2 and the separate
media collection from 3.3. Publication scope from item 13.

**P4. Commissioned verification and the growth loop.** Bundles, the CAC and TIN
lookup for businesses not on the register, the invitation to a searched-for
business, CAC registration guidance (item 11) and obligations by business type
(item 12).

Items 4 (monthly reviews, booming sectors) and 6 (six months free) sit across
P2 and P4 and are cheap once the data is there.

---

## 7. Open decisions

1. **Publication depth.** Adopt the three depths in 3.1, or hold the current rule
   and show only claimed businesses publicly?
2. **Identity checks.** Adopt the subject-consented model in section 4, or is
   there a licensing route being pursued that changes what is lawful?
3. **Order of work after P1.** The directory first (P2) makes the platform look
   alive and gives searchers a reason to arrive. Owner identity first (P3) makes
   the badges real. Both before the paid loop.
