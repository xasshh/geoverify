/**
 * The status vocabulary.
 *
 * Five tones, each with a fixed hue AND a fixed shape. Colour is never the only
 * carrier of meaning: a supervisor reading a table in greyscale, or with a colour
 * vision deficiency, still reads the shape and the word. A supervisor learns five
 * shapes once and they never mean anything else.
 */
export type StatusTone = 'accepted' | 'review' | 'rejected' | 'progress' | 'idle';

export type StatusShape = 'circle' | 'triangle' | 'diamond' | 'square' | 'ring';

export const STATUS_SHAPE: Record<StatusTone, StatusShape> = {
    accepted: 'circle',
    review: 'triangle',
    rejected: 'diamond',
    progress: 'square',
    idle: 'ring',
};

/** Tailwind text colour class per tone. Backgrounds derive from these. */
export const STATUS_COLOR: Record<StatusTone, string> = {
    accepted: 'text-green',
    review: 'text-amber',
    rejected: 'text-alert',
    progress: 'text-gold',
    idle: 'text-graphite',
};

/** Cell assignment lifecycle, from the coverage side. */
export type CellStatus =
    | 'unassigned'
    | 'assigned'
    | 'in_progress'
    | 'submitted'
    | 'accepted'
    | 'returned';

/** Capture lifecycle, from the registry side. */
export type CaptureStatus = 'draft' | 'submitted' | 'accepted' | 'flagged' | 'rejected';

const CELL_TONE: Record<CellStatus, StatusTone> = {
    unassigned: 'idle',
    assigned: 'idle',
    in_progress: 'progress',
    submitted: 'progress',
    accepted: 'accepted',
    returned: 'review',
};

const CAPTURE_TONE: Record<CaptureStatus, StatusTone> = {
    draft: 'idle',
    submitted: 'progress',
    accepted: 'accepted',
    flagged: 'review',
    rejected: 'rejected',
};

const CELL_LABEL: Record<CellStatus, string> = {
    unassigned: 'Unassigned',
    assigned: 'Assigned',
    in_progress: 'In progress',
    submitted: 'Submitted',
    accepted: 'Accepted',
    returned: 'Returned',
};

const CAPTURE_LABEL: Record<CaptureStatus, string> = {
    draft: 'Draft',
    submitted: 'Submitted',
    accepted: 'Accepted',
    flagged: 'Needs review',
    rejected: 'Rejected',
};

export function cellStatus(status: CellStatus): { tone: StatusTone; label: string } {
    return { tone: CELL_TONE[status], label: CELL_LABEL[status] };
}

export function captureStatus(status: CaptureStatus): { tone: StatusTone; label: string } {
    return { tone: CAPTURE_TONE[status], label: CAPTURE_LABEL[status] };
}
