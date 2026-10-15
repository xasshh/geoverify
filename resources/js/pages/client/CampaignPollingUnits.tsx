import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ClientShell } from '@/components/ClientShell';
import { PollingUnitMap } from '@/components/PollingUnitMap';
import { cx } from '@/lib/cx';
import { UNIT_COLOUR, type PollingUnitSummary } from '@/lib/pollingUnits';

interface UnitDetail {
    code: string;
    name: string;
    listedPlace: string | null;
    place: string | null;
    status: 'confirmed' | 'not_found' | 'pending';
    found: string | null;
    distanceM: number | null;
    checkedOn: string | null;
    answers: { label: string; answer: string; detail: string | null; concern: boolean }[];
    photos: { url: string; label: string }[];
}

interface Props {
    campaign: { id: number; code: string; name: string };
    summary: PollingUnitSummary;
    bounds: [number, number, number, number] | null;
}

const pct = (n: number, of: number): string => (of === 0 ? '0%' : `${String(Math.round((n / of) * 100))}%`);

/**
 * A client's polling units: how far the checks have got, what they found, a
 * map of every unit and the downloads. Findings are counted over checks a
 * supervisor accepted; no officer is named anywhere on this page.
 */
export default function CampaignPollingUnits({ campaign, summary, bounds }: Props) {
    const [selected, setSelected] = useState<number | null>(null);
    const base = `/client/campaigns/${String(campaign.id)}/polling-units`;
    const settledOfAll = pct(summary.settled, summary.total);

    return (
        <ClientShell current="campaigns">
            <Head title={`Polling units · ${campaign.name}`} />

            <header className="mt-8 flex flex-wrap items-end justify-between gap-4 border-b border-rule pb-3">
                <div>
                    <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                        <Link href={`/client/campaigns/${String(campaign.id)}`} className="underline underline-offset-2">
                            {campaign.name}
                        </Link>
                        <span className="numeric-mono ml-2 text-faint">{campaign.code}</span>
                    </p>
                    <h1 className="font-display text-display-l text-ink">Polling units</h1>
                </div>
                <p className="text-ui text-muted">
                    Download:{' '}
                    <a href={`${base}/export?format=csv`} className="text-gold underline underline-offset-2">spreadsheet (CSV)</a>
                    {' · '}
                    <a href={`${base}/export?format=geojson`} className="text-gold underline underline-offset-2">map file (GeoJSON)</a>
                </p>
            </header>

            <dl className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
                {[
                    ['On the list', summary.total.toLocaleString(), null],
                    ['Checked', summary.settled.toLocaleString(), settledOfAll],
                    ['Confirmed in place', (summary.confirmed - summary.moved).toLocaleString(), null],
                    ['Found somewhere else', summary.moved.toLocaleString(), 'more than 150 m from the list'],
                    ['Could not be found', summary.notFound.toLocaleString(), null],
                ].map(([label, value, note]) => (
                    <div key={label} className="rounded-card border border-rule bg-raised p-4">
                        <dt className="text-label text-muted">{label}</dt>
                        <dd className="numeric-mono mt-1 text-[1.4rem] font-semibold text-ink">{value}</dd>
                        {note !== null && <dd className="text-label text-muted">{note}</dd>}
                    </div>
                ))}
            </dl>

            <section className="mt-6 grid gap-5 lg:grid-cols-[1fr_340px]">
                <div className="relative h-[62dvh] min-h-[420px] overflow-hidden rounded-card border border-rule">
                    {summary.total === 0 ? (
                        <p className="flex h-full items-center justify-center text-ui text-muted">The polling unit list has not been loaded yet.</p>
                    ) : (
                        <PollingUnitMap url={`${base}/points.json`} bounds={bounds} onSelect={setSelected} />
                    )}
                    <ul className="absolute bottom-3 left-3 flex flex-wrap gap-x-3 gap-y-1 rounded-sm bg-raised/95 px-3 py-2 text-label text-ink shadow-card">
                        {(
                            [
                                ['confirmed', 'Confirmed in place'],
                                ['moved', 'Found somewhere else'],
                                ['not_found', 'Not found'],
                                ['pending', 'Not checked yet (listed position)'],
                            ] as const
                        ).map(([k, l]) => (
                            <li key={k} className="flex items-center gap-1.5">
                                <span className="size-2.5 rounded-full" style={{ backgroundColor: UNIT_COLOUR[k] }} />
                                {l}
                            </li>
                        ))}
                    </ul>
                </div>

                <aside className="flex flex-col gap-4">
                    {selected === null ? (
                        <div className="rounded-card border border-rule bg-raised p-5">
                            <h2 className="text-body font-extrabold text-ink">What we found</h2>
                            <p className="mt-1 text-table text-muted">At {summary.confirmed.toLocaleString()} checked units. Press a dot on the map to see one unit.</p>
                            <ul className="mt-3 flex flex-col gap-2">
                                {summary.concerns.map((c) => (
                                    <li key={c.key} className="flex items-center justify-between gap-3 text-table">
                                        <span className="text-ink">{c.label}</span>
                                        <span className={cx('numeric-mono font-bold', c.count > 0 ? 'text-alert-ink' : 'text-faint')}>
                                            {c.count.toLocaleString()} <span className="font-normal text-muted">({pct(c.count, summary.confirmed)})</span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {summary.venues.length > 0 && (
                                <>
                                    <h3 className="mt-5 text-ui font-extrabold text-ink">Where voting happens</h3>
                                    <ul className="mt-2 flex flex-col gap-1.5">
                                        {summary.venues.map((v) => (
                                            <li key={v.key} className="flex justify-between text-table">
                                                <span className="text-ink">{v.label}</span>
                                                <span className="numeric-mono text-muted">{v.count.toLocaleString()}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            )}
                        </div>
                    ) : (
                        <Unit key={selected} url={`${base}/${String(selected)}`} onClose={() => { setSelected(null); }} />
                    )}
                </aside>
            </section>

            <section className="mt-6 overflow-x-auto rounded-card border border-rule bg-raised">
                <table className="w-full min-w-[640px] text-left text-table">
                    <thead className="text-label text-muted">
                        <tr className="border-b border-rule">
                            <th className="px-4 py-2.5">LGA (as listed)</th>
                            <th className="px-3 py-2.5 text-right">Units</th>
                            <th className="px-3 py-2.5 text-right">Checked</th>
                            <th className="px-3 py-2.5 text-right">Somewhere else</th>
                            <th className="px-3 py-2.5 text-right">Not found</th>
                            <th className="px-4 py-2.5 text-right">With a concern</th>
                        </tr>
                    </thead>
                    <tbody>
                        {summary.byLga.map((l) => (
                            <tr key={l.lga} className="border-b border-rule last:border-b-0">
                                <td className="px-4 py-2.5 font-bold text-ink">{l.lga}</td>
                                <td className="numeric-mono px-3 py-2.5 text-right">{l.total.toLocaleString()}</td>
                                <td className="numeric-mono px-3 py-2.5 text-right">{l.settled.toLocaleString()} <span className="text-muted">({pct(l.settled, l.total)})</span></td>
                                <td className="numeric-mono px-3 py-2.5 text-right">{l.moved.toLocaleString()}</td>
                                <td className="numeric-mono px-3 py-2.5 text-right">{l.notFound.toLocaleString()}</td>
                                <td className="numeric-mono px-4 py-2.5 text-right">{l.withConcerns.toLocaleString()}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </ClientShell>
    );
}

function Unit({ url, onClose }: { url: string; onClose: () => void }) {
    const [unit, setUnit] = useState<UnitDetail | null>(null);

    useEffect(() => {
        void fetch(url, { headers: { Accept: 'application/json' } })
            .then(async (r) => (r.ok ? ((await r.json()) as UnitDetail) : null))
            .then(setUnit)
            .catch(() => undefined);
    }, [url]);

    return (
        <div className="rounded-card border border-rule bg-raised p-5">
            <button type="button" onClick={onClose} className="text-table font-bold text-muted hover:text-ink">← All findings</button>
            {unit === null ? (
                <p className="mt-4 text-table text-muted">Loading…</p>
            ) : (
                <>
                    <p className="mt-3 font-mono text-[0.75rem] text-muted">{unit.code}</p>
                    <h2 className="text-body font-extrabold text-ink">{unit.name}</h2>
                    <p className="text-table text-muted">{unit.place ?? unit.listedPlace}</p>
                    {unit.status === 'pending' ? (
                        <p className="mt-3 rounded-sm bg-sunken px-3 py-2 text-table text-muted">Not checked yet. The dot is where the list puts it.</p>
                    ) : (
                        <>
                            <p className={cx('mt-3 rounded-sm px-3 py-2 text-table font-bold', unit.status === 'not_found' ? 'bg-alert-soft text-alert-ink' : 'bg-green-soft text-green')}>
                                {unit.found}
                                {unit.distanceM !== null && unit.status === 'confirmed' && ` · ${Math.round(unit.distanceM).toLocaleString()} m from the list`}
                                {unit.checkedOn !== null && ` · checked ${unit.checkedOn}`}
                            </p>
                            {unit.photos.length > 0 && (
                                <div className="mt-3 grid grid-cols-2 gap-2">
                                    {unit.photos.map((p) => (
                                        <a key={p.url} href={p.url} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-sm border border-rule">
                                            <img src={p.url} alt={p.label} className="aspect-square w-full object-cover" />
                                            <span className="block px-2 py-1 text-label text-muted">{p.label}</span>
                                        </a>
                                    ))}
                                </div>
                            )}
                            <dl className="mt-3 divide-y divide-rule">
                                {unit.answers.map((a) => (
                                    <div key={a.label} className="py-2">
                                        <dt className="text-label text-muted">{a.label}</dt>
                                        <dd className={cx('text-table font-bold', a.concern ? 'text-alert-ink' : 'text-ink')}>{a.answer}</dd>
                                        {a.detail !== null && <dd className="text-label text-muted">{a.detail}</dd>}
                                    </div>
                                ))}
                            </dl>
                        </>
                    )}
                </>
            )}
        </div>
    );
}
