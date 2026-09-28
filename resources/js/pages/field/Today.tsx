import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { FieldShell } from '@/components/FieldShell';
import { Composer, PinnedNote, Thread } from '@/components/FieldInbox';
import { FieldMap } from '@/components/FieldMap';
import { cx } from '@/lib/cx';
import { ago, clock, shortCell, type FieldCapture, type OfficerDay } from '@/lib/fieldDay';
import { useInbox } from '@/lib/offline/messages';
import { usePack } from '@/lib/offline/usePack';

const QA: Record<string, { label: string; className: string }> = {
    submitted: { label: 'Awaiting QA', className: 'bg-held-soft text-held-ink' },
    accepted: { label: 'Accepted', className: 'bg-green-soft text-green' },
    rejected: { label: 'Returned · re-capture', className: 'bg-alert-soft text-alert-ink' },
    flagged: { label: 'Escalated', className: 'bg-amber-soft text-amber-ink' },
    draft: { label: 'Draft', className: 'bg-sunken text-muted' },
};

function Stat({
    value,
    of,
    label,
    detail,
    percent,
    tone,
}: {
    value: number;
    of?: string;
    label: string;
    detail: string;
    percent: number;
    tone: 'gold' | 'held' | 'alert';
}) {
    const ring = tone === 'gold' ? 'bg-gold-soft text-gold-dark' : tone === 'held' ? 'bg-held-soft text-held-ink' : 'bg-alert-soft text-alert-ink';
    const bar = tone === 'gold' ? 'bg-gold' : tone === 'held' ? 'bg-held' : 'bg-alert';

    return (
        <section className="flex flex-col rounded-card border border-rule bg-raised px-5 py-5">
            <div className="flex items-center gap-3">
                <span className={cx('flex size-14 items-center justify-center rounded-full font-display text-display-s', ring)}>{value}</span>
                {of !== undefined && <span className="text-ui font-bold text-muted">{of}</span>}
            </div>
            <h2 className="mt-3 text-body font-extrabold text-ink">{label}</h2>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-sunken" aria-hidden="true">
                <div className={cx('h-full rounded-full', bar)} style={{ width: `${String(Math.min(100, Math.max(0, percent)))}%` }} />
            </div>
            <p className="mt-2 text-table text-muted">{detail}</p>
        </section>
    );
}

/**
 * The officer's map panel: the capture map itself, unchanged, shown for the
 * cell they should be in next. Read-only here, so opening Today starts no GPS
 * session; "Open full map" is where capture happens.
 */
function MapPanel({ day }: { day: OfficerDay }) {
    const next = day.cells.next[0];
    const pack = usePack(next?.coverageAreaId ?? 0);

    if (next === undefined) {
        return (
            <div className="flex h-full min-h-[320px] items-center justify-center rounded-card border border-rule bg-raised px-6 text-center text-ui text-muted">
                No cells are assigned to you yet. Your supervisor will send them to this device.
            </div>
        );
    }

    return (
        <div className="relative h-full min-h-[240px] overflow-hidden rounded-card border border-rule bg-sunken sm:min-h-[360px]">
            {pack.state === 'installed' && pack.pack !== null ? (
                <FieldMap
                    pack={pack.pack}
                    assignedH3={next.h3}
                    centre={next.centre}
                    position={null}
                    track={[]}
                    captured={[]}
                    visitedFootprintIds={[]}
                    selectedFootprintId={null}
                    onSelectFootprint={() => undefined}
                    onStreetChange={() => undefined}
                />
            ) : (
                <div className="flex h-full min-h-[240px] flex-col items-center justify-center gap-3 px-6 text-center sm:min-h-[360px]">
                    <p className="text-ui text-muted">The offline map for this area is not on this device yet.</p>
                    <Link href="/field/device" className="rounded-sm bg-gold px-4 py-2.5 text-ui font-extrabold text-on-accent">
                        Download the map
                    </Link>
                </div>
            )}
            <Link
                href={`/field/assignments/${String(next.assignmentId)}/capture`}
                className="absolute right-3 bottom-3 rounded-sm bg-raised px-4 py-2.5 text-ui font-bold text-ink shadow-card"
            >
                Full map
            </Link>
        </div>
    );
}

function CapturesTable({ captures }: { captures: FieldCapture[] }) {
    const [filter, setFilter] = useState<'all' | 'returned' | 'qa'>('all');
    const returned = captures.filter((c) => c.status === 'rejected');
    const awaiting = captures.filter((c) => c.status === 'submitted');
    const shown = filter === 'returned' ? returned : filter === 'qa' ? awaiting : captures;

    const chips: { key: typeof filter; label: string }[] = [
        { key: 'all', label: `All · ${String(captures.length)}` },
        { key: 'returned', label: `Returned · ${String(returned.length)}` },
        { key: 'qa', label: `Awaiting QA · ${String(awaiting.length)}` },
    ];

    return (
        <section className="mt-7" aria-labelledby="todays-captures">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="todays-captures" className="font-display text-display-s text-ink">
                    Today’s captures
                </h2>
                <div className="flex flex-wrap gap-2">
                    {chips.map((chip) => (
                        <button
                            key={chip.key}
                            type="button"
                            aria-pressed={filter === chip.key}
                            onClick={() => {
                                setFilter(chip.key);
                            }}
                            className={cx(
                                'min-h-[38px] rounded-sm border px-3.5 text-table font-bold',
                                filter === chip.key ? 'border-ink bg-ink text-inverse' : 'border-rule-strong bg-raised text-ink hover:bg-sunken',
                            )}
                        >
                            {chip.label}
                        </button>
                    ))}
                </div>
            </div>
            {shown.length === 0 ? (
                <p className="mt-4 rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted max-w-none">
                    {captures.length === 0 ? 'Nothing captured yet today.' : 'Nothing here.'}
                </p>
            ) : (
                <div className="mt-4 overflow-x-auto rounded-card border border-rule bg-raised">
                    <table className="w-full min-w-[760px] border-collapse text-ui">
                        <thead>
                            <tr className="border-b border-rule text-left text-table text-muted">
                                {['Record', 'Business', 'Type', 'Cell', 'Time', 'GPS', 'Photos', 'QA status'].map((h) => (
                                    <th key={h} className="px-4 py-3 font-bold">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((c) => {
                                const qa = QA[c.status] ?? { label: c.status, className: 'bg-sunken text-muted' };
                                const poor = c.accuracyM !== null && c.accuracyM > 10;

                                return (
                                    <tr key={c.id} className="border-b border-rule last:border-b-0">
                                        <td className="px-4 py-3 numeric-mono text-mono text-ink">{c.ref}</td>
                                        <td className="px-4 py-3 font-bold text-ink">{c.business ?? 'No business recorded'}</td>
                                        <td className="px-4 py-3 text-ink capitalize">{c.type}</td>
                                        <td className="px-4 py-3 numeric-mono text-mono text-muted">{c.cell}</td>
                                        <td className="px-4 py-3 text-ink">{clock(c.at)}</td>
                                        <td className={cx('px-4 py-3 font-bold', poor ? 'text-alert' : 'text-green')}>
                                            {c.accuracyM === null ? 'None' : `±${String(Math.round(c.accuracyM))} m`}
                                        </td>
                                        <td className="px-4 py-3 text-ink">{c.photos}</td>
                                        <td className="px-4 py-3">
                                            <span className={cx('rounded-full px-2.5 py-1 text-table font-bold', qa.className)}>{qa.label}</span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

/**
 * Today, to the enumeration mockup: start a capture, three counts, the map and
 * the supervisor beside it, then today's records. On a phone the same parts
 * stack, with the supervisor's latest as a banner above the map.
 */
export default function Today({ day }: { day: OfficerDay }) {
    const inbox = useInbox();
    const next = day.cells.next[0];
    const latest = [...inbox.messages].reverse().find((m) => m.direction === 'to_officer');
    const supervisor = day.supervisor?.name ?? 'your supervisor';
    const captureHref = next === undefined ? '/field/map' : `/field/assignments/${String(next.assignmentId)}/capture`;

    return (
        <FieldShell day={day} current="today">
            {/* Phone: the supervisor's latest, above everything. */}
            {latest !== undefined && (
                <Link href="/field/inbox" className="mb-4 flex items-center gap-3 rounded-card border border-alert/30 bg-alert-soft px-4 py-3 lg:hidden">
                    <span className="min-w-0 flex-1">
                        <span className="block text-label font-extrabold tracking-[0.05em] text-alert-ink uppercase">
                            Supervisor{inbox.unread > 0 && ` · ${String(inbox.unread)} new`}
                        </span>
                        <span className="block truncate text-ui font-bold text-ink">
                            {latest.record !== null && `${latest.record.ref} `}
                            {latest.body}
                        </span>
                    </span>
                    <span aria-hidden="true" className="text-alert-ink">›</span>
                </Link>
            )}

            {/* Tablet and up: the four cards. The phone gets the compact row below the map. */}
            <div className="hidden gap-4 sm:grid sm:grid-cols-2 xl:grid-cols-4">
                <Link href={captureHref} className="relative hidden overflow-hidden rounded-card bg-gold px-6 py-6 text-on-accent shadow-card hover:bg-gold-dark sm:flex sm:flex-col">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                        <path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11zM12 7.5v5M9.5 10h5" />
                    </svg>
                    <span className="mt-auto pt-6 font-display text-display-s">Start a capture</span>
                    <span className="mt-1 text-ui text-on-accent/85">Record a business at your current GPS position</span>
                </Link>
                <Stat
                    value={day.capturesToday}
                    of={`of ${String(day.target)}`}
                    label="Captures today"
                    percent={(day.capturesToday / Math.max(1, day.target)) * 100}
                    tone="gold"
                    detail={
                        day.pace.remaining === 0
                            ? 'Target reached'
                            : `${String(day.pace.remaining)} to go${day.pace.finishAt === null ? '' : ` · on pace to finish by ${clock(day.pace.finishAt)}`}`
                    }
                />
                <Stat
                    value={day.cells.complete}
                    of={`of ${String(day.cells.total)}`}
                    label="Cells complete"
                    percent={(day.cells.complete / Math.max(1, day.cells.total)) * 100}
                    tone="held"
                    detail={`${String(day.cells.inProgress)} in progress · ${String(day.cells.notStarted)} not started`}
                />
                <Stat
                    value={day.returned.count}
                    of="records"
                    label="Returned to fix"
                    percent={day.returned.count > 0 ? 100 : 0}
                    tone="alert"
                    detail={day.returned.count === 0 ? 'Nothing sent back' : `Oldest returned ${ago(day.returned.oldestAt)}`}
                />
            </div>

            {day.jobs.length > 0 && (
                <section className="mt-5 flex flex-col gap-2" aria-label="Inspections and visits">
                    {day.jobs.map((job) => (
                        <Link
                            key={job.id}
                            href={`/field/jobs/${String(job.id)}`}
                            className="flex items-center justify-between gap-3 rounded-card border border-held/30 bg-held-soft px-4 py-3"
                        >
                            <span className="min-w-0">
                                <span className="block text-label font-extrabold tracking-[0.05em] text-held-ink uppercase">
                                    {job.kind === 'site_visit' ? 'Site visit' : 'Product inspection'} · {job.orderRef}
                                </span>
                                <span className="block truncate text-ui font-bold text-ink">{job.business}</span>
                            </span>
                            <span className="shrink-0 text-table font-bold text-held-ink">
                                {job.requestedFor === null ? 'Today' : new Date(job.requestedFor).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}
                            </span>
                        </Link>
                    ))}
                </section>
            )}

            <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
                <MapPanel day={day} />

                <section className="hidden flex-col rounded-card border border-rule bg-raised xl:flex" aria-labelledby="from-supervisor">
                    <header className="flex items-start justify-between gap-3 border-b border-rule px-5 py-4">
                        <div>
                            <h2 id="from-supervisor" className="text-body font-extrabold text-ink">
                                From your supervisor
                            </h2>
                            <p className="text-table text-muted">
                                {supervisor}
                                {day.supervisor?.staffRef != null && ` · ${day.supervisor.staffRef}`}
                            </p>
                        </div>
                        {inbox.unread > 0 && <span className="rounded-full bg-alert px-2.5 py-0.5 text-table font-extrabold text-on-accent">{inbox.unread} new</span>}
                    </header>
                    <PinnedNote messages={inbox.messages} />
                    <Thread messages={inbox.messages.slice(-6)} className="max-h-[380px] px-4 py-4" />
                    <div className="mt-auto border-t border-rule px-4 py-4">
                        <Composer to={supervisor} />
                    </div>
                </section>
            </div>

            {/* Phone: three counts in a row, as on the phone board. */}
            <div className="mt-4 grid grid-cols-3 gap-2 sm:hidden">
                {(
                    [
                        [day.capturesToday, `/${String(day.target)}`, 'Captures', 'text-gold-dark'],
                        [day.cells.complete, `/${String(day.cells.total)}`, 'Cells done', 'text-held-ink'],
                        [day.returned.count, '', 'To fix', 'text-alert-ink'],
                    ] as const
                ).map(([value, of, label, tone]) => (
                    <Link key={label} href={label === 'To fix' ? '/field/records' : '/field'} className="rounded-card border border-rule bg-raised px-3 py-3">
                        <span className={cx('block font-display text-display-s', tone)}>
                            {value}
                            <span className="text-ui font-bold text-muted">{of}</span>
                        </span>
                        <span className="block text-table font-bold text-ink">{label}</span>
                    </Link>
                ))}
            </div>

            {/* Phone: the next cells and the big button, as on the phone board. */}
            <section className="mt-5 flex flex-col divide-y divide-rule rounded-card border border-rule bg-raised lg:hidden">
                {day.cells.next.slice(0, 2).map((cell, i) => (
                    <Link key={cell.assignmentId} href={`/field/assignments/${String(cell.assignmentId)}/capture`} className="flex items-center justify-between gap-3 px-4 py-3">
                        <span>
                            <span className="block text-ui font-bold text-ink">
                                {i === 0 ? 'Next' : 'Then'}: cell {shortCell(cell.h3)}
                            </span>
                            <span className="block text-table text-muted">
                                {cell.captured} of {cell.footprints} captured
                            </span>
                        </span>
                        <span className={cx('rounded-full px-2.5 py-1 text-table font-bold', cell.started ? 'bg-amber-soft text-amber-ink' : 'bg-sunken text-muted')}>
                            {cell.started ? 'In progress' : 'Not started'}
                        </span>
                    </Link>
                ))}
            </section>
            <Link href={captureHref} className="mt-4 flex min-h-touch-xl items-center justify-center rounded-card bg-gold text-body font-extrabold text-on-accent shadow-card sm:hidden">
                + Start a capture
            </Link>

            <div className="hidden lg:block">
                <CapturesTable captures={day.captures} />
            </div>
        </FieldShell>
    );
}
