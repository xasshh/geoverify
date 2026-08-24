import { Head, Link } from '@inertiajs/react';
import { AppBar } from '@/components/AppBar';
import { StatusPill } from '@/components/StatusPill';
import { SyncIndicator } from '@/components/SyncIndicator';
import { PackShelf } from '@/components/PackShelf';
import { cx } from '@/lib/cx';
import type { StatusTone } from '@/lib/status';

interface Assignment {
    id: number;
    h3: string;
    mandate: string;
    coverageAreaId: number;
    footprints: number;
    captured: number;
    status: string;
    statusLabel: string;
    dueOn: string | null;
    overdue: boolean;
    returnReason: string | null;
    returnedCaptures: Array<{
        id: number;
        structureType: string;
        reason: string | null;
        returnedAt: string;
    }>;
}

interface Props {
    officer: { name: string; staffRef: string | null };
    assignments: Assignment[];
}

const TONE: Record<string, StatusTone> = {
    assigned: 'idle',
    in_progress: 'progress',
    submitted: 'progress',
    accepted: 'accepted',
    returned: 'review',
};

/**
 * The officer's board: their work, and nothing else.
 *
 * Dusk by default because this is the field client. Capture arrives at M4, so
 * every card here is currently a statement of what is owed, not a way in.
 */
export default function FieldAssignments({ officer, assignments }: Props) {
    const outstanding = assignments.reduce((n, a) => n + Math.max(0, a.footprints - a.captured), 0);

    // One row per mandate, not one per cell: the pack covers the whole mandate
    // and an officer with nine cells in Abuja does not need nine offers of the
    // same 67 MB.
    const mandates = Array.from(
        new Map(
            assignments.map((a) => [a.coverageAreaId, { coverageAreaId: a.coverageAreaId, mandate: a.mandate }]),
        ).values(),
    );

    return (
        <div data-mode="dusk" className="min-h-dvh bg-surface text-ink">
            <Head title="My work" />
            <AppBar variant="field" />

            <header className="sticky top-0 z-10 border-b border-rule bg-surface px-4 pt-4 pb-3">
                <div className="flex items-baseline justify-between gap-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            My work
                        </p>
                        <h1 className="font-display text-display-s text-ink">{officer.name}</h1>
                    </div>
                    {officer.staffRef !== null && (
                        <span className="numeric-mono text-mono text-faint">{officer.staffRef}</span>
                    )}
                </div>
                <div className="mt-2">
                    <SyncIndicator connectivity="online" queued={0} lastSync={null} compact />
                </div>
            </header>

            <PackShelf mandates={mandates} />

            <div className="px-4 py-4">
                <p className="numeric-mono text-ui text-muted">
                    {assignments.length} {assignments.length === 1 ? 'cell' : 'cells'} /{' '}
                    {outstanding.toLocaleString()} buildings still to visit
                </p>

                {assignments.length === 0 ? (
                    <div className="mt-8 rounded-sm border border-rule px-4 py-12 text-center">
                        <p className="text-body text-ink">No work assigned yet.</p>
                        <p className="mt-1 text-ui text-muted">
                            Your supervisor will send cells to this device.
                        </p>
                    </div>
                ) : (
                    <ul className="mt-4 flex flex-col gap-3">
                        {assignments.map((a) => (
                            <li key={a.id}>
                              <Link
                                href={`/field/assignments/${String(a.id)}/capture`}
                                className={cx(
                                    'block rounded-sm border bg-raised p-4',
                                    a.overdue ? 'border-amber' : 'border-rule',
                                )}
                              >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="numeric-mono text-mono text-ink">{a.h3}</p>
                                        <p className="mt-0.5 text-label text-faint">{a.mandate}</p>
                                    </div>
                                    <StatusPill
                                        tone={TONE[a.status] ?? 'idle'}
                                        label={a.statusLabel}
                                        size="sm"
                                    />
                                </div>

                                <div className="mt-3 flex items-baseline justify-between gap-3">
                                    <span className="numeric-mono text-ui text-muted">
                                        {a.captured} / {a.footprints} captured
                                    </span>
                                    {a.dueOn !== null && (
                                        <span
                                            className={cx(
                                                'numeric-mono text-ui',
                                                a.overdue ? 'text-amber' : 'text-faint',
                                            )}
                                        >
                                            {a.overdue ? 'Overdue ' : 'Due '}
                                            {a.dueOn}
                                        </span>
                                    )}
                                </div>

                                <div
                                    className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-sunken"
                                    role="progressbar"
                                    aria-valuenow={a.footprints === 0 ? 0 : Math.round((a.captured / a.footprints) * 100)}
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-label={`${String(a.captured)} of ${String(a.footprints)} detected structures captured`}
                                >
                                    <div
                                        className="h-full rounded-full bg-gold"
                                        style={{
                                            width: `${String(a.footprints === 0 ? 0 : Math.min(100, (a.captured / a.footprints) * 100))}%`,
                                        }}
                                    />
                                </div>

                                {a.returnReason !== null && (
                                    <p className="mt-3 border-l-2 border-amber pl-3 text-ui text-muted">
                                        <span className="block text-label font-semibold tracking-[0.12em] text-amber uppercase">
                                            Sent back
                                        </span>
                                        {a.returnReason}
                                    </p>
                                )}

                                {a.returnedCaptures.length > 0 && (
                                    <div className="mt-3 border-l-2 border-amber pl-3">
                                        <p className="text-label font-semibold tracking-[0.12em] text-amber uppercase">
                                            {a.returnedCaptures.length === 1
                                                ? '1 capture to do again'
                                                : `${String(a.returnedCaptures.length)} captures to do again`}
                                        </p>
                                        <ul className="mt-1 flex flex-col gap-1.5">
                                            {a.returnedCaptures.slice(0, 3).map((capture) => (
                                                <li key={capture.id} className="text-ui text-muted">
                                                    <span className="text-ink">
                                                        {capture.structureType}
                                                    </span>
                                                    {capture.reason !== null && `: ${capture.reason}`}
                                                </li>
                                            ))}
                                            {a.returnedCaptures.length > 3 && (
                                                <li className="text-label text-faint">
                                                    and {a.returnedCaptures.length - 3} more
                                                </li>
                                            )}
                                        </ul>
                                    </div>
                                )}

                                <p className="mt-3 text-ui font-semibold text-gold">Open this cell</p>
                              </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
