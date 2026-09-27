# Phase 4 · the portals, built to the mockups

Commissioned 2026-09-25 from `GeoVerify Portals · UI Mockups` (UI/UX guide v1,
September 2026): fifteen boards covering the public directory, the business
profile, checkout, the merchant hub, and the Global Investor and Discovery
Portal. The instruction is exact parity: the same flow, the same features, the
same buttons.

The visual system landed first (tokens, type, shells, the portal home). This
document is the rest.

---

## 1. Where the mockups meet a hard rule

Parity everywhere except these, each for a stated reason. The screen keeps its
place, its button and its flow; only the word or the datum changes.

| Mockup | Rule | Built as |
|---|---|---|
| "Escrow" in copy, states and nav ("Pay into escrow", "Wallet & escrow", "Held in escrow") | Never the word: regulated in Nigeria, we are not licensed | "Held until delivery", "Wallet", "Held". Same states, same colours, same positions |
| Buyer money held against goods, released on delivery | Money moves only on a signed webhook | Paid in through Paystack, recorded by the webhook alone, released by a ledger movement on confirmation, paid out by Paystack transfer. Needs legal sign-off before live mode (section 4) |
| Exact coordinates on the investor dossier header | Never an exact coordinate | The H3 cell at resolution 7 (about 5 km²), which the mockup's own catchment card already shows. Distances are computed in PostgreSQL from the real position and reported rounded |
| "Evidence pack · 21 photos, GPS log" offered to investors | An officer's photographs never leave | The pack is not offered. The certificate is, with the photo count stated as a fact rather than shipped |
| Unclaimed businesses in investor aggregates | Enumeration is not consent | Counts come from the directory-visible population through `DirectoryVisibility`, and an opportunity exists only because its owner published one |

## 2. New users, new guard

Investors are neither staff, parties nor the commissioning client, and they act
for an organisation that is itself vetted (KYC). They get a fourth guard,
`investor` against `investor_users`, on the same reasoning that gave the client
its own: a session that can never satisfy another surface's middleware by
construction. An investor requests access, can read the aggregates at once, and
reaches dossiers, data rooms and commissions only once an admin marks the
organisation verified.

A business reaches investors only through an **opportunity** it publishes from
the portal: what it is seeking, ticket size, use of funds, and a data room of
documents it uploads. Access to the room is requested by an investor and granted
by the business, per organisation. Withdrawing sets a status and keeps the row.

## 3. Milestones

**I1 · Investor portal foundation** (done 2026-09-25)
Guard, accounts, request access, admin KYC. Overview (action card, three counts,
verified businesses by state, sectors, featured opportunities), Opportunities,
the business dossier (score, facts, verification evidence, location and
catchment, seeking, express interest, documents, private notes), Watchlist,
Data rooms, Reports, Settings. Portal side: the business publishes an
opportunity, uploads data-room documents, grants access.

**I2 · Investor commissions and the explore map** (done 2026-09-25)
"Commission due diligence" and "Commission re-verification" as real verification
orders paid by an investor organisation (a second payer on `verification_orders`,
exactly one payer by check constraint). Explore map on H3 aggregates. Reports as
the documents those orders produce.

**M1 · Merchant hub catalogue** (done 2026-09-26)
Listings (products: name, unit, price, photographs by the business), listing
strength, Team and roles on `party_users`, Settings, the Verification page. The
public profile gains its Products tab.

**M2 · Buying: cart, checkout, held payment** (done 2026-09-27)
Cart, delivery, "Protect this order" with product inspection, site visit or
nothing, pay by card, transfer or USSD through Paystack. Orders, the timeline,
buyer confirmation, release, disputes ("Buyer raised issue"). Wallet with held
and available balances, withdrawal to a bank account.

Built in `app/Domain/Commerce`. The cart is the buyer's browser; PlacePurchase
prices from the catalogue. The one webhook routes by reference (GV-2026-000123
verification, GV-10482 product, gvpo-... withdrawal). Ledger: paid is CASH /
BUYER_FUNDS_HELD; release moves it to MERCHANT_BALANCES less commission to
COMMERCE_INCOME; a withdrawal is reserved to PAYOUTS_IN_TRANSIT and leaves CASH
only on transfer.success. Wallet balances are sums over the ledger, never a
column. `orders:release-delivered` (daily 07:15) releases dispatched orders
nobody confirmed or disputed within `release_after_days`. Admins rule on
disputes at /admin/disputes. Inspection and site visit render on checkout but
cannot be chosen until M3 (`Protection::isOffered`). A refund ruling posts the
ledger movement; the provider-side refund is sent by hand, as RefundOrder does.

**M3 · Inspections and visits**
Inspection and site-visit jobs for field agents (a new assignment kind on the
existing field client, extracted as a shared service rather than a refactor of
the field code), the inspection report with its checklist and geo-tagged photos
the buyer approves.

**M4 · Directory search and map**
The split list and map, "what" and "where", Verified only, popular chips, the
filters (open now, held payment, inspection available, delivers), H3 density,
clusters, directions, book a visit.

## 4. Decisions a person has to make

1. **Holding buyer funds.** Collecting money for goods and releasing it on
   delivery is payment intermediation. It is built and tested in Paystack test
   mode; switching to live needs counsel's view and, likely, a licensed partner
   holding the funds. Nothing in the code assumes which.
2. **Investor KYC.** What an admin checks before marking an organisation
   verified. The system records who decided and when; it does not decide.
3. **Fees.** The mockups leave `[INSPECTION FEE]`, `[VISIT FEE]`, `[NET PAYOUT]`
   and the commission rate as placeholders. They are configuration, not code.
