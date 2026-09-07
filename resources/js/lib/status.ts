/**
 * The status vocabulary.
 *
 * Five tones, each with a fixed hue AND a fixed shape. Colour is never the only
 * carrier of meaning: a supervisor reading a table in greyscale, or with a colour
 * vision deficiency, still reads the shape and the word. A supervisor learns five
 * shapes once and they never mean anything else.
 */
/**
 * `held` is the portal's addition, and it is a money state rather than a
 * workflow one: funds taken and not yet earned. It is neither `accepted`
 * (nothing has settled) nor `review` (nothing is waiting on a person), and
 * borrowing either would teach a false meaning on the screens where the meaning
 * is what the customer is buying.
 */
export type StatusTone =
    "accepted" | "review" | "rejected" | "progress" | "idle" | "held";

export type StatusShape =
    "circle" | "triangle" | "diamond" | "square" | "ring" | "half";

export const STATUS_SHAPE: Record<StatusTone, StatusShape> = {
    accepted: "circle",
    review: "triangle",
    rejected: "diamond",
    progress: "square",
    idle: "ring",
    held: "half",
};

/** Tailwind text colour class per tone. Backgrounds derive from these. */
export const STATUS_COLOR: Record<StatusTone, string> = {
    accepted: "text-green",
    review: "text-amber",
    rejected: "text-alert",
    progress: "text-gold",
    idle: "text-graphite",
    held: "text-held",
};

/** Cell assignment lifecycle, from the coverage side. */
export type CellStatus =
    | "unassigned"
    | "assigned"
    | "in_progress"
    | "submitted"
    | "accepted"
    | "returned";

/** Capture lifecycle, from the registry side. */
export type CaptureStatus =
    "draft" | "submitted" | "accepted" | "flagged" | "rejected";

const CELL_TONE: Record<CellStatus, StatusTone> = {
    unassigned: "idle",
    assigned: "idle",
    in_progress: "progress",
    submitted: "progress",
    accepted: "accepted",
    returned: "review",
};

const CAPTURE_TONE: Record<CaptureStatus, StatusTone> = {
    draft: "idle",
    submitted: "progress",
    accepted: "accepted",
    flagged: "review",
    rejected: "rejected",
};

const CELL_LABEL: Record<CellStatus, string> = {
    unassigned: "Unassigned",
    assigned: "Assigned",
    in_progress: "In progress",
    submitted: "Submitted",
    accepted: "Accepted",
    returned: "Returned",
};

const CAPTURE_LABEL: Record<CaptureStatus, string> = {
    draft: "Draft",
    submitted: "Submitted",
    accepted: "Accepted",
    flagged: "Needs review",
    rejected: "Rejected",
};

export function cellStatus(status: CellStatus): {
    tone: StatusTone;
    label: string;
} {
    return { tone: CELL_TONE[status], label: CELL_LABEL[status] };
}

export function captureStatus(status: CaptureStatus): {
    tone: StatusTone;
    label: string;
} {
    return { tone: CAPTURE_TONE[status], label: CAPTURE_LABEL[status] };
}

/** Verification order lifecycle, from the money side. */
export type OrderStatus =
    | "awaiting_payment"
    | "paid"
    | "assigned"
    | "in_progress"
    | "submitted"
    | "completed"
    | "cancelled"
    | "refunded";

/**
 * `held` covers paid and assigned, where we have the money and nobody has
 * started: that is precisely what the half filled mark means. Once an officer
 * is actually working it the state the customer cares about is progress, and
 * once a supervisor has accepted it the fee is earned.
 *
 * `refunded` takes the rejected mark, which is about outcome rather than blame.
 * The order ended without the visit happening, and that is the same shape as
 * any other ending without the thing happening, whoever's fault it was.
 */
const ORDER_TONE: Record<OrderStatus, StatusTone> = {
    awaiting_payment: "idle",
    paid: "held",
    assigned: "held",
    in_progress: "progress",
    submitted: "progress",
    completed: "accepted",
    cancelled: "idle",
    refunded: "rejected",
};

export function orderTone(status: OrderStatus): StatusTone {
    return ORDER_TONE[status];
}
