/**
 * The five rungs, in the order they are established. The order is fixed and the
 * set is closed: a ladder always shows all five.
 */
export const TIERS = [
    "listed",
    "identity_verified",
    "location_verified",
    "operations_verified",
    "monitored",
] as const;

export type Tier = (typeof TIERS)[number];

/**
 * What a rung is doing right now.
 *
 * `current`, `ageing` and `stale` are all established: the difference is how
 * long ago, and that is a fact rather than a warning. `pending` means somebody
 * has paid and an officer is on the way.
 */
export type RungState =
    "current" | "ageing" | "stale" | "pending" | "not_established";

export interface Rung {
    tier: Tier;
    state: RungState;
    /** ISO date the tier was established. Absent unless established. */
    establishedOn?: string | null;
    /** How long ago, in the reader's words: "18 months". */
    elapsed?: string | null;
    /** Queue position, when pending. */
    queueAhead?: number | null;
}

export const TIER_LABEL: Record<Tier, string> = {
    listed: "Listed",
    identity_verified: "Identity",
    location_verified: "Location",
    operations_verified: "Operations",
    monitored: "Monitored",
};

/**
 * What each rung actually means, in a trader's words rather than ours.
 *
 * These are read by someone deciding whether to spend money, so they say what
 * happens, not what it is called.
 */
/**
 * The tier said as a statement rather than as a column heading.
 *
 * The ladder needs "Location", because it sits in a column beside four other
 * rungs and the context carries the rest. A search result needs "Location
 * verified", because it sits alone in a sentence and "Location · August 2026"
 * says nothing about what happened in August.
 */
export const TIER_STATEMENT: Record<Tier, string> = {
    listed: "Listed",
    identity_verified: "Identity verified",
    location_verified: "Location verified",
    operations_verified: "Operations verified",
    monitored: "Monitored",
};

export const TIER_MEANING: Record<Tier, string> = {
    // Two ways onto this rung: an officer recorded the business, or its owner
    // registered it. The wording has to be true of both, so it says what the
    // rung means rather than how the record got here.
    listed: "The business is on the register with a contact we can reach.",
    identity_verified:
        "We checked that the person or company behind it is real.",
    location_verified:
        "An officer went to the address and confirmed it is there.",
    operations_verified: "An officer saw it trading, and at what scale.",
    monitored: "We check again every three months so it stays current.",
};

/**
 * Shape carries the state as well as colour, so the ladder reads in greyscale
 * and to a reader who cannot separate the hues. Established is a filled disc,
 * stale is a triangle, pending is a square, unclimbed is an open ring.
 */
export const STATE_GLYPH: Record<RungState, string> = {
    current: "●",
    ageing: "●",
    stale: "▲",
    pending: "■",
    not_established: "○",
};

export const STATE_COLOR: Record<RungState, string> = {
    current: "text-green",
    ageing: "text-green",
    stale: "text-amber-ink",
    pending: "text-gold",
    not_established: "text-graphite",
};
