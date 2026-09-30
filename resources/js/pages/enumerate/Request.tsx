import { Head, Link, router } from '@inertiajs/react';
import { useEffect, type CSSProperties } from 'react';
import { EnumerateShell } from '@/components/EnumerateShell';
import { TierBadge } from '@/components/EnumerateParts';
import { cx } from '@/lib/cx';
import { DAY_TONE, companyType, day, kobo, moment, registration, type CalendarDay, type EnumerateFrame, type RequestRow } from '@/lib/enumerate';

interface Check {
    outcome: 'matched' | 'mismatched' | 'not_found' | 'unavailable';
    note: string | null;
    facts: Record<string, unknown> | null;
    checkedAt: string;
}

interface Props {
    frame: EnumerateFrame;
    request: RequestRow & {
        rcNumber: string;
        companyType: string;
        registeredAddress: string | null;
        requestedBy: string | null;
        steps: { key: string; label: string; at: string | null; state: 'done' | 'current' | 'todo' | 'failed' }[];
        registry: {
            outcome: 'passed' | 'failed' | null;
            reason: string | null;
            decidedAt: string | null;
            cac: Check | null;
            tin: Check | null;
        };
        returnedMinor: number;
        location: Location | null;
        monitoring: Monitoring | null;
        updates: { label: string; at: string }[];
        result: { score: number; finding: string; final: boolean } | null;
    };
}

interface Monitoring {
    startsOn: string;
    endsOn: string;
    days: number;
    dayToday: number | null;
    calendar: CalendarDay[];
    missedDays: number;
    returnedMinor: number;
    entries: {
        day: number;
        date: string;
        state: 'open' | 'low' | 'closed';
        opens: string | null;
        closes: string | null;
        staff: number | null;
        customers: number | null;
        activity: string;
        photos: number;
        agentRef: string | null;
        at: string | null;
    }[];
}

/**
 * Tier 3's daily activity log, to board 30: the period as a calendar, and the
 * accepted logs newest first. A day nobody logged says so; it is what the
 * requester is refunded for when monitoring closes.
 */
function MonitoringSection({ monitoring, status }: { monitoring: Monitoring | null; status: string }) {
    if (monitoring === null) {
        return (
            <section className="rounded-card border border-dashed border-rule-strong bg-raised px-6 py-5">
                <h2 className="flex items-baseline gap-2.5 text-[1.1875rem] font-extrabold text-ink">
                    <span className="text-[0.6875rem] tracking-[0.06em] text-muted uppercase">Tier 3</span>
                    Daily activity log
                </h2>
                <p className="mt-2 text-ui text-muted">Daily visits start the day after the location is confirmed.</p>
            </section>
        );
    }

    const legend: [CalendarDay['state'], string][] = [
        ['open', 'Open and trading'],
        ['low', 'Open, low activity'],
        ['closed', 'Closed'],
        ['no_visit', 'No visit (Sunday or holiday)'],
        ['missed', 'No log'],
        ['upcoming', 'Upcoming'],
    ];

    return (
        <section className="rounded-card border border-rule bg-raised px-6 py-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="flex items-baseline gap-2.5 text-[1.1875rem] font-extrabold text-ink">
                    <span className="text-[0.6875rem] tracking-[0.06em] text-muted uppercase">Tier 3</span>
                    Daily activity log
                </h2>
                <span className={cx('rounded-full px-3 py-1 text-table font-extrabold', status === 'completed' ? 'bg-gold-soft text-gold-dark' : 'bg-held-soft text-held-ink')}>
                    {status === 'completed' ? 'Complete' : 'In progress'} · {day(monitoring.startsOn)} to {day(monitoring.endsOn)}
                </span>
            </div>

            <ol className="mt-4 grid grid-cols-7 gap-1.5 sm:grid-cols-10 lg:grid-cols-15" aria-label="Days of the monitoring period">
                {monitoring.calendar.map((c) => (
                    <li
                        key={c.date}
                        title={`Day ${String(c.day)}, ${day(c.date)}`}
                        className={cx(
                            'flex aspect-square flex-col items-center justify-center rounded-[8px] border text-[0.75rem] leading-tight font-extrabold',
                            DAY_TONE[c.state],
                            c.today && 'ring-2 ring-held ring-offset-1',
                        )}
                    >
                        {c.day}
                        <span className="text-[0.5625rem] font-bold opacity-80">{c.weekday}</span>
                    </li>
                ))}
            </ol>
            <p className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[0.75rem] text-muted">
                {legend.map(([state, label]) => (
                    <span key={state} className="flex items-center gap-1.5">
                        <span className={cx('inline-block size-3 rounded-[3px] border', DAY_TONE[state])} aria-hidden="true" />
                        {label}
                    </span>
                ))}
            </p>

            {monitoring.returnedMinor > 0 && (
                <p className="mt-3 rounded-sm bg-gold-soft px-4 py-2.5 text-ui text-gold-dark">
                    {monitoring.missedDays} trading {monitoring.missedDays === 1 ? 'day' : 'days'} had no log: {kobo(monitoring.returnedMinor)} went back to your wallet.
                </p>
            )}

            {monitoring.entries.length === 0 ? (
                <p className="mt-4 text-ui text-muted">The first day’s log appears here once our supervisor has checked it.</p>
            ) : (
                <ul className="mt-4 flex flex-col divide-y divide-rule border-t border-rule">
                    {monitoring.entries.map((e) => (
                        <li key={e.date} className="grid gap-2 py-4 sm:grid-cols-[110px_minmax(0,1fr)_auto]">
                            <div>
                                <p className="text-ui font-extrabold text-ink">Day {e.day}</p>
                                <p className="text-table text-muted">{new Date(e.date).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}</p>
                            </div>
                            <div>
                                <p className="text-ui text-ink">{e.activity}</p>
                                <p className="mt-2 flex flex-wrap gap-1.5 text-[0.75rem] font-bold">
                                    <span className={cx('rounded-[6px] px-2 py-0.5', e.state === 'open' ? 'bg-gold-soft text-gold-dark' : e.state === 'low' ? 'bg-amber-soft text-amber-ink' : 'bg-sunken text-ink')}>
                                        {e.state === 'closed' ? 'Closed' : e.state === 'low' ? 'Low activity' : 'Open'}
                                        {e.opens !== null && ` ${e.opens} to ${e.closes ?? '?'}`}
                                    </span>
                                    {e.staff !== null && <span className="rounded-[6px] bg-sunken px-2 py-0.5 text-ink">{e.staff} staff</span>}
                                    {e.customers !== null && <span className="rounded-[6px] bg-sunken px-2 py-0.5 text-ink">{e.customers} customers seen</span>}
                                    <span className="rounded-[6px] bg-sunken px-2 py-0.5 text-ink">{e.photos} {e.photos === 1 ? 'photo' : 'photos'}</span>
                                </p>
                            </div>
                            <p className="font-mono text-table text-muted sm:text-right">
                                {e.agentRef ?? ''}
                                {e.at !== null && ` · ${new Date(e.at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

interface Location {
    status: 'assigned' | 'submitted' | 'accepted' | 'returned';
    agentRef: string | null;
    assignedAt: string;
    arrivedAt: string | null;
    submittedAt: string | null;
    confirmedAt: string | null;
    revisits: number;
    area: string | null;
    distanceM: number | null;
    atAddress: boolean | null;
    minutesOnSite: number | null;
    checklist: { key: string; label: string; passed: boolean; detail: string | null }[] | null;
    photos: { id: number; kind: string; at: string | null; url: string }[];
    otherPhotos: number;
}

/**
 * The site visit, to board 30's Tier 2 section: the officer's code and
 * progress while it runs, and once a supervisor confirms it, what was found,
 * how far the premises are from the registered address, and the storefront
 * and signage photographs. Never a map pin or a coordinate: the distance is
 * the answer to the question the requester asked.
 */
function LocationSection({ location, note }: { location: Location | null; note: string }) {
    const confirmed = location?.status === 'accepted';

    return (
        <section className={cx('rounded-card bg-raised px-6 py-5', confirmed ? 'border border-rule' : 'border border-dashed border-rule-strong')}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="flex items-baseline gap-2.5 text-[1.1875rem] font-extrabold text-ink">
                    <span className="text-[0.6875rem] tracking-[0.06em] text-muted uppercase">Tier 2</span>
                    Location verification
                </h2>
                {confirmed && location.confirmedAt !== null && (
                    <span className="rounded-full bg-gold-soft px-3 py-1 text-table font-extrabold text-gold-dark">✓ Location confirmed · {moment(location.confirmedAt)}</span>
                )}
                {location !== null && !confirmed && (
                    <span className="rounded-full bg-held-soft px-3 py-1 text-table font-extrabold text-held-ink">
                        {location.submittedAt !== null ? 'Report with the supervisor' : location.arrivedAt !== null ? 'Agent on site' : 'Agent on the way'}
                    </span>
                )}
            </div>

            {location === null ? (
                <p className="mt-2 text-ui text-muted">{note}</p>
            ) : !confirmed ? (
                <p className="mt-2 text-ui text-muted">
                    Agent {location.agentRef ?? ''} was assigned {moment(location.assignedAt)}
                    {location.arrivedAt !== null && ` and arrived ${moment(location.arrivedAt)}`}.
                    {location.submittedAt !== null && ' A supervisor is checking the report; you will see the findings and photographs once it is confirmed.'}
                    {location.revisits > 0 && ` The agent was asked to visit again ${location.revisits === 1 ? 'once' : `${String(location.revisits)} times`} to get it right.`}
                </p>
            ) : (
                <>
                    <div className="mt-4 grid gap-5 md:grid-cols-[220px_minmax(0,1fr)]">
                        <div className="flex flex-col justify-center rounded-card bg-sunken px-5 py-5">
                            <p className="font-display text-[2rem] leading-none font-extrabold text-ink">
                                {location.distanceM === null ? '·' : `${String(Math.round(location.distanceM))} m`}
                            </p>
                            <p className="mt-1.5 text-table text-muted">from the CAC registered address, where the agent recorded their arrival</p>
                            {location.area !== null && <p className="mt-3 text-table font-bold text-ink">{location.area}</p>}
                        </div>
                        <ul className="flex flex-col gap-2.5">
                            {(location.checklist ?? []).map((c) => (
                                <li key={c.key} className="flex items-start gap-3 text-ui">
                                    <span
                                        aria-hidden="true"
                                        className={cx('mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[0.6875rem] font-extrabold', c.passed ? 'bg-gold-soft text-gold-dark' : 'bg-amber-soft text-amber-ink')}
                                    >
                                        {c.passed ? '✓' : '!'}
                                    </span>
                                    <span className="flex flex-1 flex-wrap justify-between gap-x-4">
                                        <span className="text-ink">
                                            <span className="sr-only">{c.passed ? 'Yes: ' : 'No: '}</span>
                                            {c.label}
                                        </span>
                                        {c.detail !== null && <span className="text-muted">{c.detail}</span>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <p className="mt-4 text-table text-muted">
                        Visited by agent {location.agentRef ?? ''}
                        {location.minutesOnSite !== null && ` · ${String(location.minutesOnSite)} ${location.minutesOnSite === 1 ? 'minute' : 'minutes'} on site`}
                        {location.otherPhotos > 0 && ` · ${String(location.otherPhotos)} more ${location.otherPhotos === 1 ? 'photograph' : 'photographs'} of the inside reviewed by our supervisor, not shared`}
                    </p>

                    {location.photos.length > 0 && (
                        <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                            {location.photos.map((p) => (
                                <a key={p.id} href={p.url} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-card border border-rule">
                                    <img src={p.url} alt={`${p.kind} of the premises`} className="aspect-[4/3] w-full object-cover" />
                                    <span className="block px-3 py-1.5 text-table">
                                        <span className="font-bold text-ink">{p.kind}</span>
                                        {p.at !== null && <span className="text-muted"> · {new Date(p.at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}</span>}
                                    </span>
                                </a>
                            ))}
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

/** The dark card's words, for where the request stands. */
function resultSoFar(request: Props['request'], waiting: boolean, failed: boolean): { title: string; body: string } {
    if (waiting) {
        return { title: 'Checking the registers', body: 'CAC and FIRS are being asked, and a supervisor reads their answers. This usually takes minutes in working hours.' };
    }

    if (failed) {
        return { title: 'Not as described', body: request.registry.reason ?? '' };
    }

    if (request.tier === 1) {
        return { title: 'Registered as described', body: 'CAC and FIRS agree with each other and with the name you searched.' };
    }

    const location = request.location;

    if (location?.status === 'accepted') {
        const found = location.checklist?.find((c) => c.key === 'premises_found')?.passed === true;
        const open = location.checklist?.find((c) => c.key === 'open_during_visit')?.passed === true;
        // The measured distance outranks the officer's tick: found, but not
        // where CAC says, is a different finding.
        const away = found && location.atAddress === false && location.distanceM !== null;

        return {
            title: away ? `Found ${String(Math.round(location.distanceM ?? 0))} m from the stated address` : found ? 'Found at the stated address' : 'Not at the registered address',
            body:
                request.status === 'monitoring'
                    ? 'The location is confirmed. Daily visits follow for the monitoring period.'
                    : `${found ? 'Registered, and found where CAC says it is' : 'Registered, but the agent found it elsewhere or not at all'}${open ? ', open during the visit.' : '.'}`,
        };
    }

    if (location !== null) {
        return { title: 'Agent at work', body: 'The registry check passed and an agent has the visit. Findings appear once a supervisor confirms them.' };
    }

    return { title: 'Registry check passed', body: 'An officer is being assigned. The field visit comes next.' };
}

const text = (v: unknown): string => (typeof v === 'string' && v !== '' ? v : '·');

function Row({ label, value, strong = true }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex justify-between gap-4 py-1.5 text-ui">
            <dt className="text-muted">{label}</dt>
            <dd className={cx('text-right', strong ? 'font-bold text-ink' : 'text-ink')}>{value}</dd>
        </div>
    );
}

function Outcome({ check }: { check: Check | null }) {
    if (check === null) {
        return <span className="text-table font-bold text-muted">Waiting</span>;
    }

    const label = { matched: 'Matches', mismatched: 'Does not match', not_found: 'Not found', unavailable: 'No answer yet' }[check.outcome];
    const tone = check.outcome === 'matched' ? 'text-gold-dark' : check.outcome === 'unavailable' ? 'text-muted' : 'text-alert-ink';

    return <span className={cx('text-table font-extrabold', tone)}>{label}</span>;
}

/**
 * A request's own page, to board 30: the progress bar, the registry check as
 * the registers answered it, the site visit once an officer has one, and the
 * updates. The daily-activity log joins with E3.
 */
export default function RequestPage({ frame, request }: Props) {
    const cac = request.registry.cac?.facts ?? null;
    const tin = request.registry.tin?.facts ?? null;
    const directors = Array.isArray(cac?.directors) ? (cac.directors as { name: string; role: string }[]) : [];
    const waiting = request.status === 'paid' || request.status === 'registry_check';

    const finished = request.status === 'passed' || request.status === 'failed' || request.status === 'completed';

    // Until it is finished, look again every thirty seconds: the registers,
    // the officer and the supervisor each move it on their own time.
    useEffect(() => {
        if (finished) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['request', 'frame'] });
        }, 30_000);

        return () => { window.clearInterval(timer); };
    }, [finished]);

    const passed = request.registry.outcome === 'passed';
    const failed = request.registry.outcome === 'failed';
    const verdict = resultSoFar(request, waiting, failed);

    return (
        <EnumerateShell
            current="requests"
            frame={frame}
            crumbs={<Link href="/enumerate/verifications" className="font-bold text-gold hover:text-gold-dark">← My verifications</Link>}
            actions={
                <>
                    <Link
                        href={`/enumerate/support?request=${request.reference}`}
                        className="flex h-11 items-center rounded-sm border border-rule-strong bg-raised px-4 text-ui font-bold text-ink hover:bg-sunken"
                    >
                        Raise a complaint
                    </Link>
                    <a
                        href={`/enumerate/verifications/${request.reference}/report.pdf`}
                        className="flex h-11 items-center gap-2 rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                            <path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 20h14" />
                        </svg>
                        {request.result?.final === true ? 'Export PDF report' : 'Interim PDF report'}
                    </a>
                </>
            }
            title={
                <span className="flex flex-wrap items-center gap-3">
                    {request.business}
                    <TierBadge tier={request.tier} label={request.tierLabel} />
                </span>
            }
        >
            <Head title={request.business} />

            <p className="-mt-2 mb-5 font-mono text-table text-muted">
                {request.reference} · {registration(request.rcNumber, request.companyType)} · Requested {day(request.requestedAt)}
                {request.requestedBy !== null && ` by ${request.requestedBy}`} · Paid {kobo(request.amountMinor)} from wallet
            </p>

            <ol
                className="mb-6 grid grid-cols-3 gap-3 md:[grid-template-columns:var(--steps)]"
                style={{ '--steps': `repeat(${String(request.steps.length)}, minmax(0, 1fr))` } as CSSProperties}
            >
                {request.steps.map((s) => (
                    <li key={s.key} className="min-w-0">
                        <span
                            className={cx(
                                'block h-1.5 rounded-full',
                                s.state === 'done' ? 'bg-gold' : s.state === 'failed' ? 'bg-alert' : s.state === 'current' ? 'bg-held' : 'bg-rule',
                            )}
                        />
                        <span className={cx('mt-2 block truncate text-table font-extrabold', s.state === 'current' ? 'text-held-ink' : s.state === 'todo' ? 'text-muted' : 'text-ink')}>
                            {s.label}
                        </span>
                        <span className="block truncate text-[0.75rem] text-muted">{s.at !== null ? moment(s.at) : s.state === 'current' ? 'In progress' : ''}</span>
                    </li>
                ))}
            </ol>

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
                <div className="flex flex-col gap-5">
                    <section className="rounded-card border border-rule bg-raised px-6 py-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="flex items-baseline gap-2.5 text-[1.1875rem] font-extrabold text-ink">
                                <span className="text-[0.6875rem] tracking-[0.06em] text-muted uppercase">Tier 1</span>
                                Registry check
                            </h2>
                            {passed && <span className="rounded-full bg-gold-soft px-3 py-1 text-table font-extrabold text-gold-dark">✓ Passed · {moment(request.registry.decidedAt)}</span>}
                            {failed && <span className="rounded-full bg-alert-soft px-3 py-1 text-table font-extrabold text-alert-ink">Failed · {moment(request.registry.decidedAt)}</span>}
                            {waiting && <span className="rounded-full bg-held-soft px-3 py-1 text-table font-extrabold text-held-ink">Checking the registers</span>}
                        </div>

                        {failed && request.registry.reason !== null && (
                            <p className="mt-4 rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">{request.registry.reason}</p>
                        )}

                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            <div className="rounded-card bg-sunken px-4 py-4">
                                <p className="flex items-center justify-between text-ui font-extrabold text-ink">
                                    Corporate Affairs Commission
                                    <Outcome check={request.registry.cac} />
                                </p>
                                {cac === null ? (
                                    <p className="mt-3 text-table text-muted">{request.registry.cac?.note ?? 'The register has not answered yet.'}</p>
                                ) : (
                                    <dl className="mt-2">
                                        <Row label="Registered name" value={text(cac.name)} />
                                        <Row label={request.companyType === 'BUSINESS_NAME' ? 'BN number' : 'RC number'} value={text(cac.rcNumber)} />
                                        <Row label="Type" value={companyType(text(cac.companyType))} strong={false} />
                                        <Row label="Status" value={text(cac.status)} />
                                        <Row label="Incorporated" value={typeof cac.incorporatedOn === 'string' ? day(cac.incorporatedOn) : '·'} />
                                        <Row label="Directors" value={directors.length === 0 ? '·' : directors.map((d) => d.name).join(', ')} />
                                        <Row label="Registered address" value={text(cac.address)} />
                                    </dl>
                                )}
                                {cac !== null && request.registry.cac?.note != null && <p className="mt-2 text-table font-semibold text-alert-ink">{request.registry.cac.note}</p>}
                            </div>

                            <div className="rounded-card bg-sunken px-4 py-4">
                                <p className="flex items-center justify-between text-ui font-extrabold text-ink">
                                    Tax identification (FIRS)
                                    <Outcome check={request.registry.tin} />
                                </p>
                                {tin === null ? (
                                    <p className="mt-3 text-table text-muted">{request.registry.tin?.note ?? 'The register has not answered yet.'}</p>
                                ) : (
                                    <dl className="mt-2">
                                        <Row label="TIN" value={text(tin.tin)} />
                                        <Row label="Name on TIN" value={request.registry.tin?.outcome === 'matched' ? 'Matches CAC name' : text(tin.nameOnTin)} />
                                        <Row label="Checked against" value="FIRS TIN register" strong={false} />
                                    </dl>
                                )}
                                {tin !== null && request.registry.tin?.note != null && <p className="mt-2 text-table font-semibold text-alert-ink">{request.registry.tin.note}</p>}
                            </div>
                        </div>
                    </section>

                    {request.tier > 1 && (
                        <LocationSection
                            location={request.location}
                            note={
                                failed
                                    ? `No officer was sent: the registers did not describe the business you asked about. ${kobo(request.returnedMinor)} went back to your wallet.`
                                    : passed
                                      ? 'The registry check passed. A supervisor is assigning an officer near the address; you will see the visit here as it happens.'
                                      : 'Once the registry check passes, an officer is sent to the address.'
                            }
                        />
                    )}
                    {request.tier === 3 && request.status !== 'failed' && request.location?.status === 'accepted' && (
                        <MonitoringSection monitoring={request.monitoring} status={request.status} />
                    )}
                </div>

                <aside className="flex flex-col gap-5">
                    <section className="rounded-card bg-ink px-5 py-5 text-inverse">
                        <p className="text-[0.6875rem] font-extrabold tracking-[0.06em] text-inverse/60 uppercase">Result so far</p>
                        {request.result !== null ? (
                            <div className="mt-3 flex items-center gap-4">
                                <span
                                    className="flex size-[76px] shrink-0 items-center justify-center rounded-full font-display text-[1.75rem] font-extrabold"
                                    style={{ background: `conic-gradient(var(--color-logo) ${String(request.result.score * 3.6)}deg, rgb(255 255 255 / 0.12) 0deg)` }}
                                    aria-label={`Score ${String(request.result.score)} out of 100`}
                                >
                                    <span className="flex size-[62px] items-center justify-center rounded-full bg-ink">{request.result.score}</span>
                                </span>
                                <span>
                                    <span className="block font-display text-[1.25rem] leading-tight font-extrabold">{request.result.finding}</span>
                                    <span className="mt-1 block text-table text-inverse/75">{verdict.body}</span>
                                </span>
                            </div>
                        ) : (
                            <>
                                <p className="mt-2 font-display text-[1.375rem] font-extrabold">{verdict.title}</p>
                                <p className="mt-1.5 text-table text-inverse/75">{verdict.body}</p>
                            </>
                        )}
                        {request.result !== null && (
                            <p className="mt-3 border-t border-white/10 pt-3 text-[0.75rem] text-inverse/60">
                                {request.result.final ? 'Final score, as printed on the report.' : 'Score so far. The final score is set when monitoring closes.'}
                            </p>
                        )}
                    </section>

                    <section className="rounded-card border border-rule bg-raised px-5 py-5">
                        <h2 className="text-body font-extrabold text-ink">Updates</h2>
                        <ul className="mt-3 flex flex-col gap-3">
                            {request.updates.slice(0, 8).map((u, i) => (
                                <li key={`${u.at}-${String(i)}`} className="flex gap-3">
                                    <span className={cx('mt-1.5 size-2 shrink-0 rounded-full', i === 0 ? 'bg-held' : 'bg-gold')} aria-hidden="true" />
                                    <span>
                                        <span className="block text-ui font-bold text-ink">{u.label}</span>
                                        <span className="block text-[0.75rem] text-muted">{moment(u.at)}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {request.updates.length > 8 && (
                            <p className="mt-3 text-table text-muted">and {request.updates.length - 8} earlier</p>
                        )}
                    </section>
                </aside>
            </div>
        </EnumerateShell>
    );
}
