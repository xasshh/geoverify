import { Head, Link, router } from '@inertiajs/react';
import { AppBar } from '@/components/AppBar';
import { consoleLinks } from '@/lib/consoleNav';
import { DataTable, type Column } from '@/components/DataTable';
import { PresenceMark, type TracePoint } from '@/components/PresenceMark';
import { cx } from '@/lib/cx';

interface Flag {
    signal: string;
    verdict: 'warn' | 'fail';
    message: string;
    deduction: number;
}

interface QueueRow {
    id: number;
    score: number | null;
    observedAt: string;
    officer: string;
    officerId: number;
    structureId: number;
    structureType: string;
    plusCode: string | null;
    h3: string;
    enterprises: number;
    photographs: number;
    flags: Flag[];
    trace: TracePoint[];
}

interface ReviewProps {
    queue: QueueRow[];
    awaiting: number;
    officers: Array<{ id: number; name: string }>;
    filters: { officer: number | null };
}

/** Confidence reads as a word as well as a number, so it survives greyscale. */
function scoreTone(score: number | null): string {
    if (score === null) {
        return 'text-faint';
    }

    return score >= 75 ? 'text-green' : score >= 45 ? 'text-amber' : 'text-alert';
}

function whenObserved(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * The review queue.
 *
 * Ordered worst first, because the point of scoring was to put the captures most
 * likely to be wrong in front of the person who can do something about them. A
 * queue ordered by arrival reviews the honest work first and runs out of
 * afternoon before it reaches the rest.
 *
 * The Presence Mark column is doing real work here rather than decorating: at
 * 16px it carries density, not route, so a walked day reads as a dense figure
 * and a fabricated one as a line. A supervisor scanning two hundred rows finds
 * the fabricated day without opening a single record.
 */
export default function Review({ queue, awaiting, officers, filters }: ReviewProps) {
    const columns: Array<Column<QueueRow>> = [
        {
            key: 'mark',
            header: 'Trace',
            width: '52px',
            render: (row) => (
                <PresenceMark
                    points={row.trace}
                    size={26}
                    tone={row.score !== null && row.score < 45 ? 'alert' : 'gold'}
                    showCapturePoint={false}
                    label={`Walking trace behind this capture, ${String(row.trace.length)} points`}
                />
            ),
        },
        {
            key: 'score',
            header: 'Conf.',
            numeric: true,
            width: '72px',
            render: (row) => (
                <span className={cx('numeric-mono text-mono font-semibold', scoreTone(row.score))}>
                    {row.score ?? 'not scored'}
                </span>
            ),
        },
        {
            key: 'record',
            header: 'Record',
            render: (row) => (
                <span className="flex flex-col gap-0.5">
                    <span className="text-ui text-ink">{row.structureType}</span>
                    <span className="numeric-mono text-label text-faint">
                        {row.plusCode ?? row.h3}
                    </span>
                </span>
            ),
        },
        {
            key: 'officer',
            header: 'Officer',
            render: (row) => <span className="text-ui text-muted">{row.officer}</span>,
        },
        {
            key: 'observed',
            header: 'Captured',
            render: (row) => (
                <span className="numeric-mono text-mono text-muted">{whenObserved(row.observedAt)}</span>
            ),
        },
        {
            key: 'contents',
            header: 'Contents',
            numeric: true,
            width: '108px',
            render: (row) => (
                <span className="numeric-mono text-mono text-muted">
                    {row.enterprises} biz / {row.photographs} ph
                </span>
            ),
        },
        {
            key: 'flags',
            header: 'Flags',
            render: (row) =>
                row.flags.length === 0 ? (
                    <span className="text-label text-faint">None</span>
                ) : (
                    <span className="flex flex-col gap-0.5">
                        {row.flags.slice(0, 2).map((flag) => (
                            <span
                                key={flag.signal}
                                className={cx(
                                    'text-label',
                                    flag.verdict === 'fail' ? 'text-alert' : 'text-amber',
                                )}
                            >
                                {flag.verdict === 'fail' ? '!' : '?'} {flag.message}
                            </span>
                        ))}
                        {row.flags.length > 2 && (
                            <span className="text-label text-faint">
                                and {row.flags.length - 2} more
                            </span>
                        )}
                    </span>
                ),
        },
    ];

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title="Review queue" />
            <AppBar
                variant="console"
                links={consoleLinks('review')}
            />

            <div className="mx-auto max-w-7xl px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            Supervision
                        </p>
                        <h1 className="font-display text-display-m text-ink">Review queue</h1>
                    </div>
                    <p className="numeric-mono text-mono text-muted">
                        {awaiting} awaiting a decision
                    </p>
                </header>

                <div className="mt-6 flex flex-wrap items-center gap-3">
                    <label className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        Officer
                    </label>
                    <select
                        value={filters.officer ?? ''}
                        onChange={(event) => {
                            router.get(
                                '/console/review',
                                event.target.value === '' ? {} : { officer: event.target.value },
                                { preserveState: true, replace: true },
                            );
                        }}
                        className="rounded-sm border border-rule-strong bg-surface px-3 py-1.5 text-ui text-ink"
                    >
                        <option value="">Everyone</option>
                        {officers.map((officer) => (
                            <option key={officer.id} value={officer.id}>
                                {officer.name}
                            </option>
                        ))}
                    </select>
                </div>

                <p className="mt-6 max-w-[68ch] text-ui text-muted">
                    Worst confidence first. The score routes a capture here with its evidence laid
                    out; it does not decide anything. Nothing is rejected by a number.
                </p>

                <div className="mt-4">
                    <DataTable
                        columns={columns}
                        rows={queue}
                        rowKey={(row) => String(row.id)}
                        caption="Captures awaiting a supervisor's decision, worst confidence first"
                        onRowActivate={(row) => {
                            router.visit(`/console/review/${String(row.id)}`);
                        }}
                        empty={
                            <span className="text-ui text-muted">
                                Nothing is waiting. Every capture has been decided.
                            </span>
                        }
                    />
                </div>

                {queue.length > 0 && (
                    <p className="mt-4 text-label text-faint">
                        Showing {queue.length} of {awaiting}.{' '}
                        <Link href="/console/coverage" className="underline underline-offset-2">
                            Coverage
                        </Link>
                    </p>
                )}
            </div>
        </div>
    );
}
