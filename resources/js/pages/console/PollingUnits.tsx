import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { cx } from '@/lib/cx';
import { ago } from '@/lib/fieldDay';
import { STATUS_LABEL, type PollingUnitSummary } from '@/lib/pollingUnits';

interface Officer {
    id: number;
    name: string;
    ref: string | null;
}

interface UnitRow {
    id: number;
    code: string;
    name: string;
    place: string | null;
    status: keyof typeof STATUS_LABEL;
    distanceM: number | null;
    officer: { name: string; ref: string | null } | null;
    openCheck: { id: number; status: string; arrived: boolean } | null;
}

interface Reviewing {
    id: number;
    status: string;
    code: string;
    name: string;
    listedPlace: string | null;
    agent: { name: string; staff_ref: string | null } | null;
    arrivedAt: string | null;
    submittedAt: string | null;
    accuracyM: number | null;
    distanceM: number | null;
    position: { lat: number; lng: number } | null;
    listed: { lat: number; lng: number } | null;
    found: string | null;
    foundKey: 'at_listed' | 'elsewhere' | 'not_found' | null;
    answers: { key: string; label: string; answer: string | null; detail: string | null; concern: boolean }[];
    notes: string | null;
    photos: { id: number; kind: string; url: string; distanceM: number | null }[];
}

interface Filters {
    lga: string;
    ward: string;
    status: string;
    q: string;
}

interface Props {
    campaigns: { id: number; code: string; name: string }[];
    campaign: { id: number; code: string; name: string } | null;
    summary?: PollingUnitSummary;
    waiting?: number;
    filters?: Filters;
    lgas?: string[];
    wards?: string[];
    units?: { total: number; page: number; pages: number; rows: UnitRow[] };
    officers?: Officer[];
    reviewing?: Reviewing | null;
}

const TONE: Record<UnitRow['status'], string> = {
    listed: 'bg-sunken text-muted',
    assigned: 'bg-amber-soft text-amber-ink',
    filed: 'bg-held-soft text-held-ink',
    confirmed: 'bg-green-soft text-green',
    not_found: 'bg-alert-soft text-alert-ink',
};

/**
 * Polling unit checks for a supervisor: load the list, send officers a ward
 * at a time, and read what they found. The client sees the same summary
 * (SummarisePollingUnits) without the officers.
 */
export default function PollingUnits(props: Props) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;

    return (
        <ConsoleShell current="pollingUnits">
            <Head title="Polling units" />
            <div className="mx-auto max-w-[1320px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-end justify-between gap-4 border-b border-rule pb-3">
                    <div>
                        <h1 className="font-display text-display-l text-ink">Polling units</h1>
                        <p className="mt-1 text-ui text-muted">Load the list, send officers ward by ward, and confirm what they found at each unit.</p>
                    </div>
                    {props.campaigns.length > 1 && props.campaign !== null && (
                        <select
                            aria-label="Campaign"
                            value={props.campaign.id}
                            onChange={(e) => { router.get('/console/polling-units', { campaign: e.target.value }); }}
                            className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui"
                        >
                            {props.campaigns.map((c) => (
                                <option key={c.id} value={c.id}>{c.code} · {c.name}</option>
                            ))}
                        </select>
                    )}
                </header>

                {page.props.flash.status !== null && <p className="mt-5 max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{page.props.flash.status}</p>}
                {(['file', 'assign', 'review'] as const).map((k) => errors[k] !== undefined && (
                    <p key={k} className="mt-5 max-w-none rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">{errors[k]}</p>
                ))}

                {props.campaign === null || props.summary === undefined ? (
                    <p className="mt-8 max-w-none rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                        No campaign checks polling units yet. An administrator turns it on under a campaign's capture settings ("Polling unit checks").
                    </p>
                ) : (
                    <Body {...props} campaign={props.campaign} summary={props.summary} />
                )}
            </div>
        </ConsoleShell>
    );
}

function Body({ campaign, summary, waiting = 0, filters, lgas = [], wards = [], units, officers = [], reviewing = null }: Props & { campaign: NonNullable<Props['campaign']>; summary: PollingUnitSummary }) {
    const f: Filters = filters ?? { lga: '', ward: '', status: '', q: '' };
    const [picked, setPicked] = useState<number[]>([]);
    const [officer, setOfficer] = useState('');
    const [q, setQ] = useState(f.q);
    const go = (next: Partial<Filters & { page: number; check: number }>) => {
        router.get('/console/polling-units', { campaign: campaign.id, ...f, ...next }, { preserveScroll: true, preserveState: false });
    };
    const assign = (matching: boolean) => {
        router.post(`/console/polling-units/${String(campaign.id)}/assign`, { officer_id: officer, unit_ids: picked, matching, ...f }, { preserveScroll: true, onSuccess: () => { setPicked([]); } });
    };
    const free = (units?.rows ?? []).filter((u) => u.openCheck === null);

    return (
        <>
            <section className="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {[
                    ['On the list', summary.total],
                    ['With an officer', summary.byStatus.assigned],
                    ['Filed, to read', waiting],
                    ['Confirmed', summary.confirmed],
                    ['Moved from the list', summary.moved],
                    ['Not found', summary.notFound],
                ].map(([label, n]) => (
                    <div key={label} className="rounded-card border border-rule bg-raised px-4 py-3">
                        <p className="text-label text-muted">{label}</p>
                        <p className="numeric-mono text-[1.6rem] font-extrabold text-ink">{Number(n).toLocaleString()}</p>
                    </div>
                ))}
            </section>

            <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] xl:items-start">
                <div className="flex flex-col gap-5">
                    <Import campaignId={campaign.id} empty={summary.total === 0} withoutPoint={summary.withoutListedPoint} />

                    <section className="overflow-hidden rounded-card border border-rule bg-raised">
                        <div className="flex flex-wrap items-end gap-2 border-b border-rule px-4 py-3">
                            <select aria-label="LGA" value={f.lga} onChange={(e) => { go({ lga: e.target.value, ward: '', page: 1 }); }} className="h-10 rounded-sm border border-rule-strong bg-raised px-2 text-table">
                                <option value="">Every LGA</option>
                                {lgas.map((l) => <option key={l} value={l}>{l}</option>)}
                            </select>
                            <select aria-label="Ward" value={f.ward} disabled={f.lga === ''} onChange={(e) => { go({ ward: e.target.value, page: 1 }); }} className="h-10 rounded-sm border border-rule-strong bg-raised px-2 text-table disabled:opacity-50">
                                <option value="">Every ward</option>
                                {wards.map((w) => <option key={w} value={w}>{w}</option>)}
                            </select>
                            <select aria-label="Status" value={f.status} onChange={(e) => { go({ status: e.target.value, page: 1 }); }} className="h-10 rounded-sm border border-rule-strong bg-raised px-2 text-table">
                                <option value="">Any status</option>
                                {Object.entries(STATUS_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                            </select>
                            <form onSubmit={(e) => { e.preventDefault(); go({ q, page: 1 }); }} className="flex flex-1 gap-2">
                                <input value={q} onChange={(e) => { setQ(e.target.value); }} placeholder="Code or name" aria-label="Search" className="h-10 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-3 text-table" />
                            </form>
                        </div>

                        <div className="flex flex-wrap items-center gap-2 border-b border-rule bg-sunken px-4 py-3">
                            <select aria-label="Officer" value={officer} onChange={(e) => { setOfficer(e.target.value); }} className="h-10 rounded-sm border border-rule-strong bg-raised px-2 text-table">
                                <option value="">Choose an officer</option>
                                {officers.map((o) => <option key={o.id} value={o.id}>{o.name}{o.ref === null ? '' : ` · ${o.ref}`}</option>)}
                            </select>
                            <Button size="console" variant="primary" disabled={officer === '' || picked.length === 0} onClick={() => { assign(false); }}>
                                Send {picked.length === 0 ? 'ticked' : picked.length} {picked.length === 1 ? 'unit' : 'units'}
                            </Button>
                            <Button size="console" variant="secondary" disabled={officer === '' || (units?.total ?? 0) === 0} onClick={() => { assign(true); }}>
                                Send every unit shown ({(units?.total ?? 0).toLocaleString()})
                            </Button>
                            <span className="text-label text-muted">Units already with an officer are left alone.</span>
                        </div>

                        {units === undefined || units.rows.length === 0 ? (
                            <p className="max-w-none px-6 py-10 text-center text-ui text-muted">No polling units match.</p>
                        ) : (
                            <table className="w-full text-left text-table">
                                <thead className="text-label text-muted">
                                    <tr className="border-b border-rule">
                                        <th className="w-10 px-4 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label="Tick every free unit on this page"
                                                checked={free.length > 0 && free.every((u) => picked.includes(u.id))}
                                                onChange={(e) => { setPicked(e.target.checked ? free.map((u) => u.id) : []); }}
                                            />
                                        </th>
                                        <th className="px-2 py-2">Unit</th>
                                        <th className="px-2 py-2">Status</th>
                                        <th className="px-2 py-2">Officer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {units.rows.map((u) => (
                                        <tr key={u.id} className="border-b border-rule last:border-b-0">
                                            <td className="px-4 py-2.5 align-top">
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Tick ${u.code}`}
                                                    disabled={u.openCheck !== null}
                                                    checked={picked.includes(u.id)}
                                                    onChange={(e) => { setPicked(e.target.checked ? [...picked, u.id] : picked.filter((id) => id !== u.id)); }}
                                                />
                                            </td>
                                            <td className="px-2 py-2.5 align-top">
                                                <p className="font-mono text-[0.75rem] text-muted">{u.code}</p>
                                                <p className="font-bold text-ink">{u.name}</p>
                                                {u.place !== null && <p className="text-label text-muted">{u.place}</p>}
                                            </td>
                                            <td className="px-2 py-2.5 align-top">
                                                <span className={cx('rounded-full px-2 py-0.5 text-label font-bold whitespace-nowrap', TONE[u.status])}>{STATUS_LABEL[u.status]}</span>
                                                {u.distanceM !== null && u.distanceM > 150 && <p className="mt-1 text-label text-amber-ink">{Math.round(u.distanceM).toLocaleString()} m from the list</p>}
                                            </td>
                                            <td className="px-2 py-2.5 align-top">
                                                {u.officer === null ? <span className="text-faint">None</span> : (
                                                    <>
                                                        <p className="text-ink">{u.officer.name}</p>
                                                        {u.openCheck?.status === 'submitted' ? (
                                                            <Link href={`/console/polling-units?campaign=${String(campaign.id)}&check=${String(u.openCheck.id)}`} preserveScroll className="text-label font-bold text-gold">Read the check →</Link>
                                                        ) : u.openCheck !== null && !u.openCheck.arrived ? (
                                                            <button type="button" onClick={() => { router.post(`/console/polling-units/checks/${String(u.openCheck?.id)}/withdraw`, {}, { preserveScroll: true }); }} className="text-label font-bold text-muted underline">Withdraw</button>
                                                        ) : <p className="text-label text-muted">At the unit</p>}
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {units !== undefined && units.pages > 1 && (
                            <div className="flex items-center justify-between border-t border-rule px-4 py-3 text-table">
                                <button type="button" disabled={units.page <= 1} onClick={() => { go({ page: units.page - 1 }); }} className="font-bold text-gold disabled:text-faint">← Previous</button>
                                <span className="text-muted">Page {units.page} of {units.pages} · {units.total.toLocaleString()} units</span>
                                <button type="button" disabled={units.page >= units.pages} onClick={() => { go({ page: units.page + 1 }); }} className="font-bold text-gold disabled:text-faint">Next →</button>
                            </div>
                        )}
                    </section>

                    <p className="text-table text-muted">
                        Download everything:{' '}
                        <a href={`/console/polling-units/${String(campaign.id)}/export?format=csv`} className="font-bold text-gold underline">spreadsheet (CSV)</a>{' '}
                        or <a href={`/console/polling-units/${String(campaign.id)}/export?format=geojson`} className="font-bold text-gold underline">map file (GeoJSON)</a>.
                    </p>
                </div>

                <section>
                    <h2 className="mb-3 text-body font-extrabold text-ink">Check to read</h2>
                    {reviewing === null ? (
                        <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">No check is waiting.</p>
                    ) : (
                        <Review key={reviewing.id} check={reviewing} />
                    )}
                </section>
            </div>
        </>
    );
}

function Import({ campaignId, empty, withoutPoint }: { campaignId: number; empty: boolean; withoutPoint: number }) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <section className={cx('rounded-card border px-5 py-4', empty ? 'border-gold bg-gold-soft/40' : 'border-rule bg-raised')}>
            <h2 className="text-body font-extrabold text-ink">{empty ? 'Load the polling unit list' : 'Load more, or an updated list'}</h2>
            <p className="mt-1 text-table text-muted">
                A CSV with a header row. Needed: a code and a name. Read if present: state, LGA, ward (or registration area), latitude, longitude,
                landmark or description, registered voters. A code already loaded is updated from the list; what officers found is never changed.
            </p>
            {!empty && withoutPoint > 0 && <p className="mt-1 text-table text-amber-ink">{withoutPoint.toLocaleString()} units have no position on the list. Officers find them by name.</p>}
            <form
                className="mt-3 flex flex-wrap items-center gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/console/polling-units/${String(campaignId)}/import`, { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); } });
                }}
            >
                <input type="file" accept=".csv,text/csv" aria-label="Polling unit list" onChange={(e) => { form.setData('file', e.target.files?.[0] ?? null); }} className="text-table" />
                <Button type="submit" size="console" variant="primary" busy={form.processing} disabled={form.data.file === null}>
                    Load the list
                </Button>
            </form>
        </section>
    );
}

function Review({ check }: { check: Reviewing }) {
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const decide = (decision: 'accept' | 'return') => {
        setBusy(true);
        router.post(`/console/polling-units/checks/${String(check.id)}/review`, { decision, note }, { preserveScroll: true, onFinish: () => { setBusy(false); } });
    };
    const map = check.position === null ? null : `https://www.google.com/maps/search/?api=1&query=${String(check.position.lat)},${String(check.position.lng)}`;

    return (
        <article className="flex flex-col gap-4 rounded-card border border-rule bg-raised px-5 py-5">
            <div>
                <p className="font-mono text-[0.75rem] text-muted">{check.code}</p>
                <h3 className="text-body font-extrabold text-ink">{check.name}</h3>
                {check.listedPlace !== null && <p className="text-table text-muted">{check.listedPlace}</p>}
                <p className="mt-1 text-table text-muted">
                    {check.agent?.name} · filed {ago(check.submittedAt)}
                </p>
            </div>

            <div className={cx('rounded-sm px-4 py-3 text-ui', check.foundKey === 'not_found' ? 'bg-alert-soft text-alert-ink' : check.foundKey === 'elsewhere' ? 'bg-amber-soft text-amber-ink' : 'bg-green-soft text-green')}>
                <p className="font-bold">Found: {check.found}</p>
                <p className="text-table">
                    {check.distanceM === null ? 'The list had no position to compare with.' : `${Math.round(check.distanceM).toLocaleString()} m from where the list puts it.`}
                    {check.accuracyM !== null && ` GPS accurate to ${String(Math.round(check.accuracyM))} m.`}
                    {map !== null && (
                        <>
                            {' '}
                            <a href={map} target="_blank" rel="noreferrer" className="font-bold underline">See the position</a>
                        </>
                    )}
                </p>
            </div>

            {check.photos.length > 0 && (
                <div className="grid grid-cols-3 gap-2">
                    {check.photos.map((p) => (
                        <a key={p.id} href={p.url} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-sm border border-rule">
                            <img src={p.url} alt={p.kind.replace('pu_', '')} className="aspect-square w-full object-cover" />
                            <span className="block px-2 py-1 text-label text-muted">{p.kind === 'pu_site' ? 'The unit' : p.kind === 'pu_entrance' ? 'Way in' : 'Other'}</span>
                        </a>
                    ))}
                </div>
            )}

            {check.foundKey !== 'not_found' && (
                <dl className="divide-y divide-rule rounded-sm border border-rule">
                    {check.answers.map((a) => (
                        <div key={a.key} className="grid grid-cols-[1fr_auto] gap-x-3 px-3 py-2">
                            <dt className="text-table text-muted">{a.label}</dt>
                            <dd className={cx('text-table font-bold', a.concern ? 'text-alert-ink' : 'text-ink')}>{a.answer ?? 'Not answered'}</dd>
                            {a.detail !== null && <dd className="col-span-2 text-label text-muted">{a.detail}</dd>}
                        </div>
                    ))}
                </dl>
            )}

            {check.notes !== null && (
                <p className="max-w-none rounded-sm bg-sunken px-4 py-3 text-ui text-ink">
                    <span className="font-bold">Notes:</span> {check.notes}
                </p>
            )}

            {check.status === 'submitted' ? (
                <div className="flex flex-col gap-2">
                    <textarea
                        rows={2}
                        value={note}
                        onChange={(e) => { setNote(e.target.value); }}
                        placeholder="To send it back, say what to redo (the officer reads this)"
                        aria-label="Note to the officer"
                        className="w-full rounded-sm border border-rule-strong bg-raised p-3 text-ui"
                    />
                    <div className="flex gap-2">
                        <Button variant="primary" busy={busy} onClick={() => { decide('accept'); }}>Accept</Button>
                        <Button variant="secondary" disabled={busy || note.trim().length < 10} onClick={() => { decide('return'); }}>Send back</Button>
                    </div>
                </div>
            ) : (
                <p className="text-table text-muted">This check is {check.status}.</p>
            )}
        </article>
    );
}
