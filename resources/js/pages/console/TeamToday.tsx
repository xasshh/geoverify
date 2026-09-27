import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { useLiveMap, type Live } from '@/components/LiveMap';
import { cx } from '@/lib/cx';
import { ago, clock, shortCell } from '@/lib/fieldDay';

interface Officer {
    id: number;
    name: string;
    staffRef: string | null;
    phone: string | null;
    state: 'on_site' | 'idle' | 'no_signal' | 'finished' | 'off';
    minutes: number | null;
    cell: string | null;
    capturesToday: number;
    unread: number;
}

interface QueueRow {
    id: number;
    observedAt: string | null;
    officer: string;
    structureType: string;
    enterprises: number;
    photographs: number;
    flags: { signal: string; verdict: string; message: string }[];
}

interface Props {
    campaign: { name: string; area: string | null } | null;
    stats: {
        inField: number;
        teamSize: number;
        noSignal: number;
        capturesToday: number;
        target: number;
        awaitingQa: number;
        oldestQaAt: string | null;
        gpsFlagged: number;
        alerts: string[];
    };
    officers: Officer[];
    queue: QueueRow[];
    live: Live;
    bounds: [number, number, number, number];
    cells: string[];
}

const STATE: Record<Officer['state'], { label: (o: Officer) => string; className: string }> = {
    on_site: { label: (o) => `On site${o.cell === null ? '' : ` · cell ${shortCell(o.cell)}`}`, className: 'text-green' },
    idle: { label: (o) => `Quiet · ${String(o.minutes ?? 0)} min`, className: 'text-amber-ink' },
    no_signal: { label: (o) => `No signal · ${String(o.minutes ?? 0)} min`, className: 'text-alert' },
    finished: { label: () => 'Finished for the day', className: 'text-muted' },
    off: { label: () => 'Not out today', className: 'text-muted' },
};

function Count({ value, label, detail, tone }: { value: number; label: string; detail: string; tone: 'gold' | 'held' | 'amber' | 'alert' }) {
    const ring = { gold: 'bg-gold-soft text-gold-dark', held: 'bg-held-soft text-held-ink', amber: 'bg-amber-soft text-amber-ink', alert: 'bg-alert-soft text-alert-ink' }[tone];

    return (
        <section className="flex flex-col rounded-card border border-rule bg-raised px-5 py-5">
            <div className="flex items-center gap-3">
                <span className={cx('flex size-14 shrink-0 items-center justify-center rounded-full font-display text-display-s', ring)}>{value}</span>
                <h2 className="text-body font-extrabold text-ink">{label}</h2>
            </div>
            <p className="mt-auto pt-4 text-table text-muted">{detail}</p>
        </section>
    );
}

function flagSummary(row: QueueRow): { text: string; bad: boolean } {
    const worst = row.flags.find((f) => f.verdict !== 'pass');

    return worst === undefined ? { text: 'All checks passed', bad: false } : { text: worst.message, bad: true };
}

/** The QA queue row: Return asks for a reason in place; Accept is one press. */
function QueueItem({ row }: { row: QueueRow }) {
    const [returning, setReturning] = useState(false);
    const [reason, setReason] = useState('');
    const flag = flagSummary(row);

    const decide = (decision: 'accept' | 'return') => {
        router.post(`/console/review/${String(row.id)}`, decision === 'return' ? { decision, reason } : { decision }, { preserveScroll: true });
    };

    return (
        <li className="border-b border-rule px-5 py-4 last:border-b-0">
            <div className="grid grid-cols-[88px_minmax(0,1fr)_minmax(0,190px)_48px_auto] items-center gap-3">
                <span className="numeric-mono text-mono text-ink">REC-{String(row.id).padStart(4, '0')}</span>
                <span className="min-w-0">
                    <span className="block truncate font-bold text-ink capitalize">{row.structureType.replace('_', ' ')}</span>
                    <span className="block text-table text-muted">
                        {row.officer} · {row.photographs} photos
                    </span>
                </span>
                <span title={flag.text} className={cx('line-clamp-2 text-table font-bold', flag.bad ? 'text-amber-ink' : 'text-green')}>{flag.text}</span>
                <span className="text-table text-muted">{row.observedAt === null ? '' : clock(row.observedAt)}</span>
                <span className="flex gap-2">
                    <Button
                        variant="secondary"
                        size="console"
                        onClick={() => {
                            setReturning((r) => !r);
                        }}
                    >
                        Return
                    </Button>
                    <Button
                        variant="primary"
                        size="console"
                        onClick={() => {
                            decide('accept');
                        }}
                    >
                        Accept
                    </Button>
                </span>
            </div>
            {returning && (
                <form
                    className="mt-3 flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        decide('return');
                    }}
                >
                    <input
                        value={reason}
                        onChange={(e) => {
                            setReason(e.target.value);
                        }}
                        placeholder="What the officer needs to fix. They see this in their inbox."
                        className="h-10 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink"
                    />
                    <Button type="submit" variant="destructive" size="console" disabled={reason.trim().length < 8}>
                        Send back
                    </Button>
                </form>
            )}
        </li>
    );
}

function SendToOfficers({ officers, cells }: { officers: Officer[]; cells: string[] }) {
    const form = useForm<{ audience: 'team' | 'selected' | 'cells'; body: string; officer_ids: number[]; cells: string[]; pin: boolean }>({
        audience: 'team',
        body: '',
        officer_ids: [],
        cells: [],
        pin: false,
    });
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const count =
        form.data.audience === 'team' ? officers.length : form.data.audience === 'selected' ? form.data.officer_ids.length : form.data.cells.length;

    const toggle = (key: 'officer_ids' | 'cells', value: number | string) => {
        const list = form.data[key] as (number | string)[];
        form.setData(key, (list.includes(value) ? list.filter((v) => v !== value) : [...list, value]) as never);
    };

    return (
        <section className="rounded-card border border-rule bg-raised px-5 py-5" aria-labelledby="send">
            <h2 id="send" className="font-display text-display-s text-ink">
                Send to officers
            </h2>
            <div className="mt-3 flex flex-wrap gap-2" role="radiogroup" aria-label="Who">
                {(
                    [
                        ['team', 'Whole team'],
                        ['selected', 'Selected officers'],
                        ['cells', 'By cell'],
                    ] as const
                ).map(([value, label]) => (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={form.data.audience === value}
                        onClick={() => {
                            form.setData('audience', value);
                        }}
                        className={cx('min-h-[36px] rounded-full border px-3.5 text-table font-bold', form.data.audience === value ? 'border-ink bg-ink text-inverse' : 'border-rule-strong text-ink')}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {form.data.audience === 'selected' && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {officers.map((o) => (
                        <label key={o.id} className="flex items-center gap-1.5 rounded-sm border border-rule px-2 py-1 text-table">
                            <input type="checkbox" checked={form.data.officer_ids.includes(o.id)} onChange={() => { toggle('officer_ids', o.id); }} />
                            {o.name}
                        </label>
                    ))}
                </div>
            )}
            {form.data.audience === 'cells' && (
                <div className="mt-3 flex max-h-32 flex-wrap gap-2 overflow-y-auto">
                    {cells.map((h3) => (
                        <label key={h3} className="flex items-center gap-1.5 rounded-sm border border-rule px-2 py-1 numeric-mono text-table">
                            <input type="checkbox" checked={form.data.cells.includes(h3)} onChange={() => { toggle('cells', h3); }} />
                            {shortCell(h3)}
                        </label>
                    ))}
                </div>
            )}
            <textarea
                rows={3}
                value={form.data.body}
                onChange={(e) => {
                    form.setData('body', e.target.value);
                }}
                placeholder="Rain expected after 2 pm. Prioritise open sites before noon."
                className="mt-3 w-full rounded-sm border border-rule-strong bg-raised p-3 text-ui text-ink focus:border-gold focus:outline-2 focus:outline-gold"
            />
            <label className="mt-2 flex items-center gap-2 text-ui text-ink">
                <input type="checkbox" checked={form.data.pin} onChange={(e) => { form.setData('pin', e.target.checked); }} className="size-4 accent-[var(--color-gold)]" />
                Pin to top of officers’ dashboards
            </label>
            {errors.broadcast !== undefined && <p className="mt-2 text-ui text-alert">{errors.broadcast}</p>}
            <div className="mt-4">
                <Button
                    variant="primary"
                    size="field"
                    fullWidth
                    busy={form.processing}
                    disabled={form.data.body.trim() === '' || count === 0}
                    onClick={() => {
                        form.post('/console/broadcasts', {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                            },
                        });
                    }}
                >
                    Send to {count} {form.data.audience === 'cells' ? (count === 1 ? 'cell' : 'cells') : count === 1 ? 'officer' : 'officers'}
                </Button>
            </div>
        </section>
    );
}

/**
 * Team today, to the supervisor board. The map is the Live page's map,
 * unchanged: the same hook draws it, on the same data and the same polling.
 */
export default function TeamToday({ campaign, stats, officers, queue, live, bounds, cells }: Props) {
    const flash = usePage().props.flash.status;
    const { container, data, refreshedAt } = useLiveMap({ live, bounds, filters: { area: null } });

    return (
        <ConsoleShell current="team">
            <Head title="Team today" />
            <div className="mx-auto max-w-[1400px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-start justify-between gap-4 border-b border-rule pb-4">
                    <div>
                        <h1 className="font-display text-display-l text-ink">Team · Today</h1>
                        {campaign !== null && (
                            <p className="mt-1.5 flex flex-wrap items-center gap-2 text-table text-muted">
                                <span className="rounded-sm bg-gold-soft px-2 py-0.5 font-bold text-gold-dark">Active campaign</span>
                                <span className="font-bold text-ink">{campaign.name}</span>
                                {campaign.area !== null && <span>· {campaign.area}</span>}
                            </p>
                        )}
                    </div>
                    <div className="flex gap-3">
                        <Link href="/console/coverage" className="flex min-h-touch items-center rounded-sm border border-rule-strong px-4 text-ui font-bold text-ink hover:bg-sunken">
                            Reassign cells
                        </Link>
                        <a href="#send" className="flex min-h-touch items-center rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark">
                            Broadcast to team
                        </a>
                    </div>
                </header>

                {flash !== null && <p role="status" className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{flash}</p>}

                <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Count
                        value={stats.inField}
                        label="Officers in field"
                        tone="gold"
                        detail={`of ${String(stats.teamSize)} on your team${stats.noSignal > 0 ? ` · ${String(stats.noSignal)} without signal` : ''}`}
                    />
                    <Count
                        value={stats.capturesToday}
                        label="Captures today"
                        tone="held"
                        detail={`Target ${String(stats.target)} · ${String(stats.target === 0 ? 0 : Math.round((stats.capturesToday / stats.target) * 100))}%`}
                    />
                    <Count
                        value={stats.awaitingQa}
                        label="Waiting for QA"
                        tone="amber"
                        detail={`${stats.oldestQaAt === null ? 'Nothing waiting' : `Oldest ${ago(stats.oldestQaAt)}`} · ${String(stats.gpsFlagged)} flagged for GPS accuracy`}
                    />
                    <Count value={stats.alerts.length} label="Alerts" tone="alert" detail={stats.alerts.length === 0 ? 'Nothing needs you' : stats.alerts.join(' · ')} />
                </div>

                <div className="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="relative h-[480px] overflow-hidden rounded-card border border-rule bg-raised">
                        <div ref={container} className="h-full w-full" data-testid="live-map" />
                        <span className="pointer-events-none absolute top-3 left-3 rounded-sm bg-ink px-3 py-1.5 text-table font-bold text-inverse">
                            Live · updated {refreshedAt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}
                        </span>
                        {data.officers.length === 0 && (
                            <p className="pointer-events-none absolute inset-0 flex items-center justify-center text-ui text-faint">
                                Nobody has opened a session in the last eighteen hours.
                            </p>
                        )}
                    </div>

                    <section className="flex flex-col rounded-card border border-rule bg-raised" aria-labelledby="officers">
                        <header className="flex items-baseline justify-between border-b border-rule px-5 py-4">
                            <h2 id="officers" className="text-body font-extrabold text-ink">
                                Officers
                            </h2>
                            <span className="text-table text-muted">
                                {stats.inField} of {stats.teamSize} in field
                            </span>
                        </header>
                        {officers.length === 0 ? (
                            <p className="px-5 py-6 text-ui text-muted">Nobody holds cells you assigned yet.</p>
                        ) : (
                            <ul className="max-h-[400px] divide-y divide-rule overflow-y-auto">
                                {officers.map((o) => (
                                    <li key={o.id} className="flex items-center gap-3 px-5 py-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-sm bg-gold-soft text-table font-extrabold text-gold-dark">
                                            {o.name
                                                .split(/\s+/)
                                                .slice(0, 2)
                                                .map((p) => p.replace(/[^A-Za-z]/g, '').charAt(0))
                                                .join('')}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-ui font-bold text-ink">{o.name}</span>
                                            <span className={cx('block text-table', STATE[o.state].className)}>{STATE[o.state].label(o)}</span>
                                        </span>
                                        <span className="text-right">
                                            <span className="block font-extrabold text-ink">{o.capturesToday}</span>
                                            <span className="block text-table text-muted">today</span>
                                        </span>
                                        <Link
                                            href={`/console/messages/${String(o.id)}`}
                                            aria-label={`Message ${o.name}`}
                                            className="relative flex size-9 items-center justify-center rounded-sm border border-rule-strong text-ink hover:bg-sunken"
                                        >
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
                                                <path d="M2.6 3h10.8v7.4H6.4L3.4 13v-2.6h-.8z" />
                                            </svg>
                                            {o.unread > 0 && <span className="absolute -top-1 -right-1 size-3 rounded-full bg-alert" />}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href="/console/live" className="mt-auto border-t border-rule px-5 py-3 text-ui font-bold text-gold hover:text-gold-dark">
                            Open the live map
                        </Link>
                    </section>
                </div>

                <div className="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <section className="rounded-card border border-rule bg-raised" aria-labelledby="qa">
                        <header className="flex items-baseline justify-between border-b border-rule px-5 py-4">
                            <h2 id="qa" className="font-display text-display-s text-ink">
                                QA review queue · {stats.awaitingQa}
                            </h2>
                            <Link href="/console/review" className="text-ui font-bold text-gold hover:text-gold-dark">
                                Open review mode
                            </Link>
                        </header>
                        {queue.length === 0 ? (
                            <p className="px-5 py-6 text-ui text-muted">Nothing is waiting for review.</p>
                        ) : (
                            <ul className="overflow-x-auto">
                                {queue.map((row) => (
                                    <QueueItem key={row.id} row={row} />
                                ))}
                            </ul>
                        )}
                    </section>
                    <SendToOfficers officers={officers} cells={cells} />
                </div>
            </div>
        </ConsoleShell>
    );
}
