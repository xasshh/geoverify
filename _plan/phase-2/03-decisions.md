# Phase 2 · settled decisions

Approved 2026-08-27. These are the answers the build plan was waiting on.
Recorded here because neither is derivable from the code, and a wrong guess on
either means rework across M3 and M6.

---

## 1. Claim evidence thresholds

Approved as proposed in the survey.

| Evidence | Outcome |
|---|---|
| OTP to the phone captured at enumeration | **Auto-approve** |
| CAC verified **and** claimant identity verified **and** claimant on the returned director roster | **Auto-approve** |
| CAC verified, claimant identity verified, claimant **not** on the roster | Review |
| Documents only (CAC certificate, tenancy, utility, signage photograph) | Review |
| Location proximity only | **Never sufficient.** Supporting evidence only, never decisive |
| Any evidence, but the listing is already claimed | **Dispute.** Never auto-approve, whatever the strength |

**The sub-question, settled: control is one decision, not per tier.** A claim
grants management of a listing. It does not grant a tier. Tiers are established
by verification, which is a separate act with its own evidence and, above
`identity_verified`, its own fee. Making the claim bar vary by tier would mean a
claimant could reach a stronger tier by claiming harder rather than by being
verified, which inverts the entire product.

**Why proximity is never sufficient.** A device position is trivially
falsifiable, and Phase 1 exists because we know that: `MockLocationSignal` is
the heaviest signal in the confidence scorer precisely because a mock provider
is free and installs in thirty seconds. Accepting a self-reported position as
proof of control would contradict the anti-spoofing argument the register is
sold on.

---

## 2. Fees and SLA

Picked, with reasoning. **These are data, not code:** they live in a
`verification_prices` table resolved at order creation and stamped onto the
order, so a price change never rewrites the history of what someone was
charged.

### The ladder

| Tier | Standard | Express | What the money buys |
|---|---|---|---|
| `listed` | **Free** | — | Self-registration. Charging to exist on the register would suppress the coverage the register is for. |
| `identity_verified` | **₦2,500** | — | A NIN or CAC check through a licensed channel. Priced near marginal cost: this is the rung that makes the ladder start moving, and a barrier here costs us the ones above. |
| `location_verified` | **₦15,000** | **₦25,000** | An officer attends, records a GPS fix, photographs the front, files a report. The core product. |
| `operations_verified` | **₦35,000** | **₦50,000** | A longer visit: signage, staff present, trading observed, documents sighted. Roughly twice the officer time of a location visit. |
| `monitored` | **₦9,000 per cycle** | — | Re-verification of an established tier every 90 days. |

**Why ₦15,000 for the core product.** A targeted single-structure visit is
roughly a quarter of an officer day once travel and supervisor review are
counted. It sits close to what a Nigerian business name registration costs, so
it reads as an ordinary cost of being a legitimate business rather than a
premium. Low enough that a market trader can consider it; high enough that the
field bench pays for itself between mandates, which is the reason this phase
exists.

**Why express is roughly 1.7x rather than 2x.** Express does not cost us much
more to perform; it costs us queue priority, which is a scheduling concession
rather than a labour one. Pricing it at double would be charging for urgency we
are not actually incurring.

### SLA and refund

| Urgency | Committed | Refund |
|---|---|---|
| Standard | **10 working days** | Full and automatic if unattended by day 10 |
| Express | **3 working days** | Full and automatic if unattended by day 3 |

Working days, not calendar days, and the definition is Nigerian public
holidays plus weekends. The refund is automatic on breach: a customer should
never have to ask for it, and a customer who has to ask is a customer who
writes about it.

**A negative finding is a completed verification and is billable.** If the
officer attends and the business is not at that address, the work was performed
and the report is delivered. This is stated on the money screen in the same type
size as the promise, above the pay button, per the design plan.

### Geography

**A zone multiplier, not bespoke pricing.**

| Zone | Multiplier | Definition |
|---|---|---|
| A | **1.0** | Wards with an active mandate or a serviced cluster |
| B | **1.5** | Everywhere else inside a covered LGA |

Resolved server-side from the structure's ward at order creation, by the same
`ST_Contains` hierarchy the field platform already uses. Never client-supplied.
Two zones rather than a distance formula because a customer can be told which
zone they are in and why, and a formula produces a number nobody can argue with
or predict.

### `monitored` is a standing order, not a subscription

**M6 does not build recurring billing.** `monitored` is a standing order that
generates a discrete verification order every 90 days, each one paid as a normal
completion-linked payment. This keeps the ledger to one movement shape, keeps
every cycle individually refundable on SLA breach, and avoids card-on-file and
Paystack subscription machinery in the milestone that already carries the
payment integration.

It also matches what the tier actually means. `monitored` claims *it was still
true recently*, and that claim is only worth what the most recent visit is
worth. Billing per visit and verifying per visit are the same event.
