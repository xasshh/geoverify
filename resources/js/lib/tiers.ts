/**
 * The five rungs, in the order they are established. The order is fixed and the
 * set is closed: a ladder always shows all five.
 */
export const TIERS = [
    'listed',
    'identity_verified',
    'location_verified',
    'operations_verified',
    'monitored',
] as const;

export type Tier = (typeof TIERS)[number];

/**
 * What a rung is doing right now.
 *
 * `current`, `ageing` and `stale` are all established: the difference is how
 * long ago, and that is a fact rather than a warning. `pending` means somebody
 * has paid and an officer is on the way.
 */
export type RungState = 'current' | 'ageing' | 'stale' | 'pending' | 'not_established';

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
    listed: 'Listed',
    identity_verified: 'Identity',
    location_verified: 'Location',
    operations_verified: 'Operations',
    monitored: 'Monitored',
};

/**
 * What each rung actually means, in a trader's words rather than ours.
 *
 * These are read by someone deciding whether to spend money, so they say what
 * happens, not what it is called.
 */
export const TIER_MEANING: Record<Tier, string> = {
    listed: 'You told us this business exists and we reached you.',
    identity_verified: 'We checked that the person or company behind it is real.',
    location_verified: 'An officer went to the address and confirmed it is there.',
    operations_verified: 'An officer saw it trading at the size you told us.',
    monitored: 'We check again every three months so it stays current.',
};

/**
 * Shape carries the state as well as colour, so the ladder reads in greyscale
 * and to a reader who cannot separate the hues. Established is a filled disc,
 * stale is a triangle, pending is a square, unclimbed is an open ring.
 */
export const STATE_GLYPH: Record<RungState, string> = {
    current: '●',
    ageing: '●',
    stale: '▲',
    pending: '■',
    not_established: '○',
};

export const STATE_COLOR: Record<RungState, string> = {
    current: 'text-green',
    ageing: 'text-green',
    stale: 'text-amber',
    pending: 'text-gold',
    not_established: 'text-graphite',
};

