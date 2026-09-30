import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { cx } from '@/lib/cx';
import { ago } from '@/lib/fieldDay';

interface Officer {
    id: number;
    name: string;
    ref: string | null;
}

interface RequestRow {
    reference: string;
    business: string;
    rcNumber: string;
    tierLabel: string;
    registeredAddress: string | null;
    address: string | null;
    waitingSince: string | null;
    monitoring: {
        startsOn: string | null;
        endsOn: string | null;
        days: number | null;
        tradingDays: number;
        logged: number;
        missed: number;
        officer: { name: string; ref: string | null } | null;
        ended: boolean;
    } | null;
    visit: {
        id: number;
        kind: 'site' | 'monitoring';
        dayNumber: number | null;
        status: 'assigned' | 'submitted';
        agent: { name: string; ref: string | null } | null;
        arrivedAt: string | null;
        submittedAt: string | null;
    } | null;
}

interface Reviewing {
    id: number;
    kind: 'site' | 'monitoring';
    dayNumber: number | null;
    log: { state: 'open' | 'low' | 'closed'; opens: string | null; closes: string | null; staff: number | null; customers: number | null; activity: string } | null;
    status: 'assigned' | 'submitted' | 'accepted' | 'returned';
    reference: string | null;
    business: string | null;
    tierLabel: string | null;
    registeredAddress: string | null;
    agent: { name: string; ref: string | null } | null;
    area: string | null;
    pin: string;
    arrival: string | null;
    arrivedAt: string | null;
    distanceM: number | null;
    accuracyM: number | null;
    submittedAt: string | null;
    checklist: { key: string; label: string; passed: boolean; detail: string | null }[] | null;
    notes: string | null;
    reviewNote: string | null;
    photos: { id: number; kind: string; url: string; distanceM: number | null; fromCamera: boolean }[];
}

/** A maps search for the CAC address: where the supervisor finds the premises to pin. */
function mapsSearch(address: string, business: string): string {
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${business}, ${address}, Nigeria`)}`;
}

function Send({ row, officers }: { row: RequestRow; officers: Officer[] }) {
    const [officer, setOfficer] = useState<number | ''>('');
    const [pin, setPin] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-col gap-2 sm:flex-row">
                <input
                    value={pin}
                    onChange={(e) => { setPin(e.target.value); }}
                    placeholder="Pin: 9.0421, 7.4912"
                    aria-label={`Premises pin for ${row.business}`}
                    className="h-9 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-2 font-mono text-table text-ink"
                />
                <select
                    value={officer}
                    onChange={(e) => { setOfficer(e.target.value === '' ? '' : Number(e.target.value)); }}
                    aria-label={`Officer for ${row.business}`}
                    className="h-9 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-2 text-ui text-ink"
                >
                    <option value="">Choose an officer</option>
                    {officers.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.name}
                            {o.ref !== null ? ` · ${o.ref}` : ''}
                        </option>
                    ))}
                </select>
                <Button
                    variant="primary"
                    busy={busy}
                    disabled={officer === '' || pin.trim() === ''}
                    onClick={() => {
                        setBusy(true);
                        router.post(`/console/enumerate-visits/requests/${row.reference}/assign`, { officer_id: officer, pin }, {
                            preserveScroll: true,
                            onFinish: () => { setBusy(false); },
                        });
                    }}
                >
                    {row.visit === null ? 'Send' : 'Reassign'}
                </Button>
            </div>
            {row.address !== null && (
                <a href={mapsSearch(row.address, row.business)} target="_blank" rel="noreferrer" className="text-table font-bold text-gold hover:text-gold-dark">
                    Find “{row.address}” in Maps, then copy the point here ↗
                </a>
            )}
        </div>
    );
}

/** A Tier 3 in its monitoring period: progress, the officer, and closing it. */
function Monitoring({ row, officers }: { row: RequestRow; officers: Officer[] }) {
    const [officer, setOfficer] = useState<number | ''>('');
    const m = row.monitoring;

    if (m === null) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 rounded-sm bg-sunken px-3 py-3">
            <p className="text-table text-ink">
                <span className="font-bold">Monitoring</span> {m.startsOn} to {m.endsOn} · {m.logged} of {m.tradingDays} trading days logged
                {m.missed > 0 && <span className="font-bold text-alert-ink"> · {m.missed} missed</span>} · {m.officer?.name ?? 'no officer'}
            </p>
            <div className="flex flex-wrap gap-2">
                <select
                    value={officer}
                    onChange={(e) => { setOfficer(e.target.value === '' ? '' : Number(e.target.value)); }}
                    aria-label={`Monitoring officer for ${row.business}`}
                    className="h-9 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-2 text-ui text-ink"
                >
                    <option value="">Change the officer</option>
                    {officers.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.name}
                            {o.ref !== null ? ` · ${o.ref}` : ''}
                        </option>
                    ))}
                </select>
                <Button
                    disabled={officer === ''}
                    onClick={() => { router.post(`/console/enumerate-visits/requests/${row.reference}/monitoring-officer`, { officer_id: officer }, { preserveScroll: true }); }}
                >
                    Change
                </Button>
                <Button
                    variant="primary"
                    disabled={!m.ended}
                    onClick={() => { router.post(`/console/enumerate-visits/requests/${row.reference}/close`, {}, { preserveScroll: true }); }}
                >
                    Close monitoring
                </Button>
            </div>
            {!m.ended && <p className="text-[0.75rem] text-muted">Closes after {m.endsOn}. Trading days without an accepted log are returned to the wallet pro rata.</p>}
        </div>
    );
}

function Review({ visit }: { visit: Reviewing }) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState<'accept' | 'return' | null>(null);

    const decide = (accept: boolean) => {
        setBusy(accept ? 'accept' : 'return');
        router.post(`/console/enumerate-visits/${String(visit.id)}/decide`, { accept, note: note.trim() === '' ? null : note }, {
            preserveScroll: true,
            onFinish: () => { setBusy(null); },
        });
    };

    return (
        <section className="overflow-hidden rounded-card border border-rule bg-raised">
            <div className="border-b border-rule px-5 py-4">
                <p className="font-mono text-table text-muted">{visit.reference}</p>
                <h2 className="text-[1.25rem] font-extrabold text-ink">{visit.business}</h2>
                <p className="text-table text-muted">
                    {visit.tierLabel} · {visit.agent?.name ?? 'officer'}
                    {visit.agent?.ref !== null && visit.agent?.ref !== undefined ? ` (${visit.agent.ref})` : ''} · filed {ago(visit.submittedAt)}
                </p>
            </div>

            <dl className="grid gap-x-6 gap-y-2 px-5 py-4 text-table sm:grid-cols-2">
                <div><dt className="text-muted">Registered address</dt><dd className="font-bold text-ink">{visit.registeredAddress ?? '·'}</dd></div>
                <div><dt className="text-muted">Area (from the pin)</dt><dd className="font-bold text-ink">{visit.area ?? 'outside the loaded boundaries'}</dd></div>
                <div>
                    <dt className="text-muted">Arrival</dt>
                    <dd className="font-bold text-ink">
                        {visit.distanceM === null ? 'not recorded' : `${String(Math.round(visit.distanceM))} m from the pin`}
                        {visit.accuracyM !== null && <span className="font-normal text-muted"> (±{Math.round(visit.accuracyM)} m)</span>}
                    </dd>
                </div>
                <div><dt className="text-muted">Pin · arrival</dt><dd className="font-mono text-ink">{visit.pin} · {visit.arrival ?? '·'}</dd></div>
            </dl>

            {visit.log !== null && (
                <div className="mx-5 rounded-sm border border-rule px-4 py-3">
                    <p className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">Day {visit.dayNumber} log</p>
                    <p className="mt-1 text-ui font-bold text-ink">
                        {visit.log.state === 'open' ? 'Open, trading' : visit.log.state === 'low' ? 'Open, quiet' : 'Closed'}
                        {visit.log.opens !== null && ` · ${visit.log.opens} to ${visit.log.closes ?? '?'}`}
                        {visit.log.staff !== null && ` · ${String(visit.log.staff)} staff`}
                        {visit.log.customers !== null && ` · ${String(visit.log.customers)} customers`}
                    </p>
                    <p className="mt-1 text-ui text-ink">{visit.log.activity}</p>
                </div>
            )}

            {visit.checklist !== null && (
                <ul className="mx-5 flex flex-col divide-y divide-rule rounded-sm border border-rule">
                    {visit.checklist.map((c) => (
                        <li key={c.key} className="flex items-start justify-between gap-4 px-4 py-2.5 text-ui">
                            <span>
                                <span className="font-bold text-ink">{c.label}</span>
                                {c.detail !== null && <span className="block text-table text-muted">{c.detail}</span>}
                            </span>
                            <span className={cx('shrink-0 rounded-full px-2.5 py-0.5 text-table font-bold', c.passed ? 'bg-green-soft text-green' : 'bg-alert-soft text-alert-ink')}>
                                {c.passed ? 'Yes' : 'No'}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {visit.notes !== null && <p className="mx-5 mt-3 max-w-none rounded-sm bg-sunken px-4 py-3 text-ui text-ink">{visit.notes}</p>}

            <div className="grid grid-cols-2 gap-3 px-5 py-4 md:grid-cols-4">
                {visit.photos.map((p) => (
                    <a key={p.id} href={p.url} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-sm border border-rule">
                        <img src={p.url} alt={`${p.kind} photograph`} className="aspect-[4/3] w-full object-cover" />
                        <span className="flex flex-col px-2 py-1 text-[0.75rem]">
                            <span className={cx('font-bold capitalize', p.kind === 'interior' || p.kind === 'other' ? 'text-muted' : 'text-ink')}>
                                {p.kind}
                                {(p.kind === 'interior' || p.kind === 'other') && ' · staff only'}
                            </span>
                            <span className="text-muted">
                                {p.distanceM === null ? '' : `${String(Math.round(p.distanceM))} m`}
                                {!p.fromCamera && ' · no camera data'}
                            </span>
                        </span>
                    </a>
                ))}
            </div>

            <div className="border-t border-rule px-5 py-4">
                {visit.status === 'submitted' ? (
                    <>
                        <label className="flex flex-col gap-1.5">
                            <span className="text-ui font-bold text-ink">Note (needed to send back; the officer reads it)</span>
                            <textarea
                                value={note}
                                onChange={(e) => { setNote(e.target.value); }}
                                rows={2}
                                maxLength={500}
                                placeholder="e.g. The signage photo is blurred: retake it from the road."
                                className="rounded-sm border border-rule-strong bg-raised px-3 py-2 text-ui text-ink focus:border-gold focus:outline-none"
                            />
                        </label>
                        {errors.note !== undefined && <p role="alert" className="mt-2 text-ui font-semibold text-alert-ink">{errors.note}</p>}
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Button variant="primary" busy={busy === 'accept'} onClick={() => { decide(true); }}>
                                Confirm the location
                            </Button>
                            <Button variant="destructive" busy={busy === 'return'} onClick={() => { decide(false); }}>
                                Send back to redo
                            </Button>
                        </div>
                        <p className="mt-2 text-table text-muted">
                            Confirming shows the requester the findings, the distance, and the storefront and signage photographs. Never the inside, never a coordinate.
                        </p>
                    </>
                ) : (
                    <p className="text-ui text-ink">
                        {visit.status === 'accepted' ? 'Location confirmed.' : visit.status === 'returned' ? `Sent back: ${visit.reviewNote ?? ''}` : 'Not filed yet.'}
                    </p>
                )}
            </div>
        </section>
    );
}

/**
 * Enumerate's site visits: requests waiting for an officer, where each officer
 * is, and the reports to read. The pin is placed from the CAC address and is
 * the officer's destination; the requester is only ever told the distance.
 */
export default function EnumerateVisits({ requests, reviewing, officers }: { requests: RequestRow[]; reviewing: Reviewing | null; officers: Officer[] }) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;

    return (
        <ConsoleShell current="enumerateVisits">
            <Head title="Verification visits" />
            <div className="mx-auto max-w-[1320px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <h1 className="font-display text-display-l text-ink">Verification visits</h1>
                    <p className="mt-1 text-ui text-muted">Enumerate Tier 2 and 3 requests that passed the desk check. Pin the premises, send an officer, confirm what they found.</p>
                </header>

                {page.props.flash.status !== null && <p className="mt-5 max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{page.props.flash.status}</p>}
                {errors.pin !== undefined && <p className="mt-5 max-w-none rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">{errors.pin}</p>}

                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:items-start">
                    <section>
                        <h2 className="mb-3 text-body font-extrabold text-ink">Requests</h2>
                        {requests.length === 0 ? (
                            <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">Nothing is waiting for an officer.</p>
                        ) : (
                            <ul className="overflow-hidden rounded-card border border-rule bg-raised">
                                {requests.map((row) => (
                                    <li key={row.reference} className="flex flex-col gap-3 border-b border-rule px-5 py-4 last:border-b-0">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="font-mono text-[0.75rem] text-muted">{row.reference} · RC {row.rcNumber}</p>
                                                <p className="truncate text-body font-bold text-ink">{row.business}</p>
                                                <p className="text-table text-muted">{row.tierLabel} · {row.address ?? row.registeredAddress ?? 'No address from CAC'} · waiting {ago(row.waitingSince)}</p>
                                            </div>
                                            {row.visit !== null && (
                                                <span className={cx('rounded-full px-2.5 py-1 text-table font-bold', row.visit.status === 'submitted' ? 'bg-green-soft text-green' : row.visit.arrivedAt !== null ? 'bg-held-soft text-held-ink' : 'bg-amber-soft text-amber-ink')}>
                                                    {(() => {
                                                        const state = row.visit.status === 'submitted' ? 'filed' : row.visit.arrivedAt !== null ? 'on site' : 'on the way';

                                                        return row.visit.kind === 'monitoring'
                                                            ? `Day ${String(row.visit.dayNumber)} ${state}`
                                                            : row.visit.status === 'submitted' ? 'Report filed' : state.charAt(0).toUpperCase() + state.slice(1);
                                                    })()}
                                                    : {row.visit.agent?.name}
                                                </span>
                                            )}
                                        </div>
                                        {row.monitoring !== null && <Monitoring row={row} officers={officers} />}
                                        {row.visit?.status === 'submitted' ? (
                                            <Link href={`/console/enumerate-visits?visit=${String(row.visit.id)}`} preserveScroll className="text-ui font-bold text-gold hover:text-gold-dark">
                                                {row.visit.kind === 'monitoring' ? `Read day ${String(row.visit.dayNumber)}’s log →` : 'Read the report →'}
                                            </Link>
                                        ) : row.monitoring !== null ? null : row.visit?.arrivedAt != null ? (
                                            <p className="text-table text-muted">Arrived {ago(row.visit.arrivedAt)}. The report follows.</p>
                                        ) : (
                                            <Send row={row} officers={officers} />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section>
                        <h2 className="mb-3 text-body font-extrabold text-ink">Report</h2>
                        {reviewing === null ? (
                            <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">No report is waiting.</p>
                        ) : (
                            <Review key={reviewing.id} visit={reviewing} />
                        )}
                    </section>
                </div>
            </div>
        </ConsoleShell>
    );
}
