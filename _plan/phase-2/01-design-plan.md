# Phase 2 · design plan

The Business & Citizen Portal. A register office, not a field instrument.

---

## 1. Palette usage

The token layer already carries two modes on the same names: `[data-mode='daylight']`
and `[data-mode='dusk']`. The field app runs dusk. The console runs daylight. **The
portal runs daylight and adds nothing to the palette.**

That is the finding, and it is worth stating plainly rather than inventing tokens
to look busy: the daylight ramp was drawn for a light, formal, document-like
surface and it already is one.

| Token | Meaning in the portal | Same as Phase 1? |
|---|---|---|
| `--color-surface` `#f7f6f3` | The paper. Every page ground. | Yes |
| `--color-raised` `#edebe6` | Cards, the ladder's inactive rungs, table stripes. | Yes |
| `--color-sunken` `#e2dfd8` | Inset wells: the fee panel, the evidence list. | Yes |
| `--color-gold` `#7e642e` | **Verification, and only verification.** The ladder, the tier badge, the certificate. Never a decorative accent, never a call-to-action colour. | Yes, and this is the load-bearing one |
| `--color-green` `#2a6555` | Established, current, settled, paid. | Yes |
| `--color-amber` `#9a5913` | Ageing, in review, awaiting an action. | Yes |
| `--color-alert` `#8f2721` | Stale, failed, disputed, refunded. | Yes |
| `--color-graphite` `#54646f` | Not established. The unclimbed rungs. | Yes |

**Two additions, both justified, both narrow.**

1. `--gv-paper-edge: #ded9cf` — a single hairline one step darker than
   `--color-rule`, for the printed-document edge on the certificate and report
   preview. The portal shows documents on screen and the existing rule token is
   too light to read as a page boundary at 360px. Daylight only.

2. `--gv-held: #4a5f7a` — the money state that is neither success nor failure.
   Funds held pending delivery is not green (nothing has settled) and not amber
   (nothing is wrong or waiting on the user). Reusing either would teach a false
   meaning in a place where the meaning is the product. A desaturated slate,
   distinct from `graphite` so "not established" and "held" never read alike.

Nothing else. No portal-only accent, no second gold.

### Status vocabulary, unchanged

`status.ts` fixes five tones to five shapes: `accepted` circle, `review`
triangle, `rejected` diamond, `progress` square, `idle` ring. **A tier colour
means the same thing here as in the console, permanently.** The portal adds
`held` as a sixth tone with its own shape (a half-filled circle) because the
money states are new, not because the portal wants a new colour.

---

## 2. Type scale

The existing scale is drawn for density: `display-l` at 34px, `body` at 15px,
`table` at 13px, all with tabular figures set on `body` rather than opted into.

A portal read at arm's length on a mid-range Android needs **more air and one
larger step**, not a different family. Newsreader for display, IBM Plex Sans for
UI, IBM Plex Mono for every figure. Same three faces, same subsets, already
self-hosted and precached.

| Step | Size / line | Use |
|---|---|---|
| `display-xl` **new** | 40 / 1.05 | The one number or name a screen is about: the fee on the money screen, the business name on a listing. Portal only. |
| `display-l` 34 / 1.05 | Page titles. |
| `display-m` 26 / 1.15 | Section heads, the ladder's current rung label. |
| `display-s` 20 / 1.25 | Card titles. |
| `body` 15 / 1.6 | **The portal's default**, where the field app defaults to `ui` at 14. One step up, because this reader is not an officer scanning a grid. |
| `ui` 14 / 1.45 | Controls, secondary labels. |
| `table` 13 / 1.4 | Multi-listing tables only. Never on the single-listing path. |
| `label` 11 / .12em caps | Field labels, kickers. |
| `mono` 13 / 1.5 | Every fee, coordinate, date, reference, party code. |

**The display serif is rationed here too**, but for a different reason than the
field app. There, high-contrast serifs thin out in sunlight. Here, a serif on
every heading reads as a template. It appears on page titles, the fee, and the
certificate. Plex Sans carries everything else.

---

## 3. Wireframes

360px. Every one of these is the mobile case; the desktop case adds columns, it
does not add features.

### 3.1 Party dashboard — single listing, the default

```
┌────────────────────────────────────────┐
│ ⬡ Nigeria Business Directory      ☰    │
├────────────────────────────────────────┤
│                                        │
│  MAMA NGOZI PROVISIONS                 │
│  Shop 4, Wuse Market · Wuse · AMAC     │
│                                        │
│  ┌──────────────────────────────────┐  │
│  │ ● Listed          Mar 2026       │  │
│  │ ● Identity        Mar 2026       │  │
│  │ ○ Location        not established│  │
│  │ ○ Operations      not established│  │
│  │ ○ Monitored       not established│  │
│  │                                  │  │
│  │  Next: confirm you are here.     │  │
│  │  We send an officer to your shop │  │
│  │  and confirm it is there.        │  │
│  │  ₦X · within 10 working days     │  │
│  │                                  │  │
│  │  [ Request verification ]        │  │
│  └──────────────────────────────────┘  │
│                                        │
│  YOUR RECORD                           │
│  Sector      47.11 Retail              │
│  Structure   Shophouse, 1 floor        │
│  Recorded    12 March 2026             │
│                                        │
│  [ Propose a correction ]              │
│                                        │
│  NOT PUBLISHED                         │
│  Your listing is private. Only you     │
│  and contracted clients can see it.    │
│  [ Publication settings ]              │
│                                        │
└────────────────────────────────────────┘
```

The ladder is the page. Everything else is beneath it. A trader with one shop
never sees a table, a filter, a business switcher or a count.

**The multi-listing case reveals structure rather than replacing the page**: at
two or more, a switcher appears above the business name and a "All businesses"
entry appears in the menu. At ten or more, "All businesses" becomes a table with
the `table` type step. Nothing changes for the single-listing user, ever.

### 3.2 Claim flow

Four screens, one decision each.

```
  FIND                    ASSERT                 PROVE                  RESULT
┌──────────────┐      ┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│ Find your    │      │ Mama Ngozi   │      │ We have a    │      │ ✓ Confirmed  │
│ business     │      │ Provisions   │      │ phone number │      │              │
│              │      │ Wuse · AMAC  │      │ on this      │      │ You now      │
│ [ name     ] │      │ Shophouse    │      │ record.      │      │ manage this  │
│              │      │              │      │              │      │ listing.     │
│  or          │      │ What is your │      │ We will text │      │              │
│ [ Use my     │      │ relationship │      │ a code to    │      │ Your listing │
│   location ] │      │ to it?       │      │ 080••••1234  │      │ is private.  │
│              │      │              │      │              │      │              │
│ ───────────  │      │ ○ I own it   │      │ [ Send code ]│      │ [ See it ]   │
│ Mama Ngozi   │      │ ○ I am a     │      │              │      │              │
│ Provisions   │      │   director   │      │ Not your     │      │              │
│ Wuse · AMAC  │      │ ○ I act for  │      │ number?      │      │              │
│ ● Listed     │      │   the owner  │      │ [ Other ways │      │              │
│              │      │              │      │   to prove ] │      │              │
│ Chidi Motors │      │ [ Continue ] │      │              │      │              │
│ Garki · AMAC │      │              │      │              │      │              │
│ ● Listed     │      │              │      │              │      │              │
└──────────────┘      └──────────────┘      └──────────────┘      └──────────────┘
```

**Find** shows trading name, ward, LGA, structure type and tier. It shows no
phone number, no owner name and no photograph — enough to recognise your own
shop, not enough to impersonate someone else's.

**Prove** leads with the strongest signal we hold and offers the rest behind one
link rather than presenting five options to someone who wants to press one
button.

**Already claimed** is its own screen, headed *This listing is already managed*,
offering *Open a dispute* as an action with a stated resolution path and an
expected timeframe. It is never an error toast.

### 3.3 Verification order and payment — one screen, no disclosure

```
┌────────────────────────────────────────┐
│ ← Request verification                 │
├────────────────────────────────────────┤
│                                        │
│  We send an officer to your shop and   │
│  confirm it is there.                  │
│                                        │
│              ₦ 15,000                  │  ← display-xl, mono
│                                        │
│  ┌──────────────────────────────────┐  │
│  │ Within        10 working days    │  │
│  │ Queue         3 orders ahead     │  │
│  │ Covers        Location verified  │  │
│  └──────────────────────────────────┘  │
│                                        │
│  WHAT YOU GET                          │
│  An officer visits, records the        │
│  position, photographs the front, and  │
│  writes a report you can show anyone.  │
│                                        │
│  WHAT YOU SHOULD KNOW                  │
│  If the officer attends and your       │
│  business is not at this address, the  │
│  work is done and the fee is charged.  │
│  You will get the report either way.   │
│                                        │
│  If we do not attend within 10 working │
│  days, you are refunded in full,       │
│  automatically.                        │
│                                        │
│  Your money is held until the report   │
│  is delivered.                         │
│                                        │
│  [ Pay ₦15,000 ]                       │
│                                        │
└────────────────────────────────────────┘
```

Fee, SLA, queue position, what it covers, the negative-finding clause and the
refund condition are all above the button. No accordion, no "see terms", no
asterisk. The negative-finding paragraph is in the same type size as the
promise, not smaller.

### 3.4 The verification ladder, all five states

```
CURRENT                 AGEING                  STALE
┌────────────────┐      ┌────────────────┐      ┌────────────────┐
│ ● Location     │      │ ● Location     │      │ ▲ Location     │
│   Mar 2026     │      │   Aug 2024     │      │   Jan 2023     │
│   green        │      │   green        │      │   amber        │
│                │      │   "18 months"  │      │   "over 3 yrs" │
└────────────────┘      └────────────────┘      └────────────────┘

NOT ESTABLISHED         IN PROGRESS
┌────────────────┐      ┌────────────────┐
│ ○ Operations   │      │ ■ Location     │
│   not          │      │   officer      │
│   established  │      │   assigned     │
│   graphite     │      │   gold         │
└────────────────┘      │   "3 ahead"    │
                        └────────────────┘
```

Five rungs, always all five, always in the same order. An unclimbed rung is
`graphite` and says *not established* in words — never a grey tick, never
absent. Shape carries the state as well as colour: `●` established, `▲` stale,
`○` not established, `■` in progress.

**Decay is a fact, not a warning.** *Verified March 2024* with an elapsed
figure beside it. No red banner, no "action required", no nagging. The next rung
carries a price and a plain sentence, because that is the honest way to make it
look worth buying.

---

## 4. The signature element

**The verification ladder.** It is on the dashboard, the listing, the order flow
and the report, and it is the thing the product is remembered by.

Executed properly means five things:

1. **All five rungs, always.** Hiding unclimbed rungs would make a `listed`
   business look finished. The ladder's job is to show what is *not* established
   as clearly as what is.
2. **Every rung carries a date or the words "not established".** No rung ever
   renders as a bare state.
3. **Shape and word before colour.** It reads in greyscale and it reads to
   someone who cannot distinguish the hues.
4. **The same component in four places at three sizes.** Compact (dashboard
   card), full (listing page), and print (report cover, no interaction). One
   component, three densities, no forks.
5. **It is drawn from the record, never passed a summary.** The rung states and
   dates come from the claim and order tables, so a ladder can never say
   something the register does not.

It is the one place gold appears at any weight. Gold means verification here,
exactly as it does in the console.

---

## 5. Self-critique

**What I would have produced for any SaaS onboarding portal, and what I changed.**

**1. A progress bar and a completion percentage.**
Onboarding portals put "your profile is 60% complete" at the top. I nearly put
one above the ladder. It is wrong here: a business at `listed` is not 20 per
cent of a business, and framing unverified as incomplete implies the platform is
owed something. **Changed to:** the ladder itself, with the next rung priced.
Nothing implies obligation; the next step is an offer with a number on it.

**2. A dashboard of cards — orders, listings, documents, activity.**
The default I reached for was a four-card grid. It is the shape every admin
template ships with, and for a trader with one shop it is four cards where three
are empty. **Changed to:** the single listing *is* the dashboard. Cards appear
only when there is more than one of a thing.

**3. "Get verified" as a green primary button.**
Green in this system means established. A button that says get-verified in the
colour that means already-verified is a small lie in the highest-stakes place.
**Changed to:** the action button is neutral; gold is reserved for what has
actually been established.

**4. A trust badge with a tick.**
The reflex is a green shield. §6 is explicit that a binary tick is where trust
platforms fail, and I had still drawn one in the first pass of the listing
header. **Changed to:** the compact ladder replaces the badge everywhere a badge
would have gone, including the public verification URL.

**5. Fee disclosure in a collapsible "Pricing details".**
The tidy layout puts the negative-finding clause behind a disclosure, because it
is the least pleasant sentence on the screen. That is precisely the dark pattern
§7 forbids and the one most likely to produce a chargeback. **Changed to:** one
screen, everything above the button, the unpleasant clause in the same size as
the pleasant one.

**6. An empty state that apologises.**
"No orders yet — get started by requesting your first verification!" was written
and deleted. **Changed to:** empty states state the fact and offer the action,
with no exclamation and no apology, per the copy rule.

---

## 6. Copy

Plain verbs. Sentence case. An action keeps its name from button to heading to
receipt: *Request verification* → *Request verification* → *Verification
requested*.

Prices and what they buy, in a trader's words:

> We send an officer to your shop and confirm it is there. ₦15,000. Within 10
> working days.

Not *Tier 3 attestation*, not *location assurance product*.

Written for a reader who is not a native English speaker and not a software
user: short sentences, no idiom, no metaphor, no jargon that a bank taught us.

**Banned, same as Phase 1:** gradient cards, glassmorphism, glow, emoji icons,
purple-to-blue, animated counters, icon-in-a-tinted-rounded-square cards, and
the phrase "AI-powered" anywhere. **Added for this phase:** the word *escrow*
anywhere at all, including state names, class names and column names. It is a
**completion-linked payment**, and the states say `payment_held` and
`released`.
