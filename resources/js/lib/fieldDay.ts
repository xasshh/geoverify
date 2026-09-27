/** ReadOfficerDay.php, as every field screen receives it. */
export interface OfficerDay {
    officer: { name: string; staffRef: string | null };
    supervisor: { name: string; staffRef: string | null; phone: string | null } | null;
    campaign: { name: string; code: string; area: string | null; day: number | null; days: number | null } | null;
    shiftStartedAt: string | null;
    capturesToday: number;
    target: number;
    pace: { remaining: number; finishAt: string | null };
    cells: {
        total: number;
        complete: number;
        inProgress: number;
        notStarted: number;
        next: {
            assignmentId: number;
            h3: string;
            captured: number;
            footprints: number;
            started: boolean;
            coverageAreaId: number;
            centre: [number, number];
        }[];
    };
    returned: { count: number; oldestAt: string | null };
    unread: number;
    captures: FieldCapture[];
}

export interface FieldCapture {
    id: number;
    ref: string;
    business: string | null;
    type: string;
    cell: string;
    at: string;
    accuracyM: number | null;
    photos: number;
    status: string;
    assignmentId: number | null;
}

export function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

export function ago(iso: string | null): string {
    if (iso === null) {
        return 'never';
    }

    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60_000));

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${String(minutes)} min ago`;
    }

    const hours = Math.floor(minutes / 60);

    return hours < 24 ? `${String(hours)}h ago` : `${String(Math.floor(hours / 24))}d ago`;
}

/** The cell id as officers say it: the last four meaningful characters. */
export function shortCell(h3: string): string {
    const trimmed = h3.replace(/f+$/, '');

    return `…${trimmed.slice(-4)}`;
}
