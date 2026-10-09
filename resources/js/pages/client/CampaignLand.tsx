import { useEffect, useMemo, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AreaMap, type MapArchive } from '@/components/AreaMap';
import { ClientShell } from '@/components/ClientShell';
import { cx } from '@/lib/cx';

interface ClassRow {
    id: number;
    key: string;
    label: string;
    geometryType: 'point' | 'line' | 'polygon';
    colour: string;
    features: number;
    areaHa: number;
    lengthKm: number;
    verified: number;
    fromField: number;
}

interface Summary {
    classes: ClassRow[];
    totals: { features: number; areaHa: number; lengthKm: number; points: number };
    verification: {
        fromDesk: number;
        samplePct: number;
        target: number;
        checksDone: number;
        checksOpen: number;
        verified: number;
        rejected: number;
        revisit: number;
        progressPct: number;
    };
}

interface Area {
    id: number;
    name: string;
    bounds: [number, number, number, number];
    features: number;
    imagery: { url: string; captured: string | null } | null;
}

interface Props {
    campaign: { id: number; code: string; name: string };
    summary: Summary;
    areas: Area[];
    formats: Array<{ key: string; label: string }>;
}

type Method = '' | 'field' | 'desk';

const VERIFICATION = [
    ['verified', 'Checked on the ground'],
    ['unverified', 'Not yet checked'],
    ['needs_revisit', 'To visit again'],
    ['rejected', 'Not found'],
] as const;

const EMPTY: GeoJSON.FeatureCollection = { type: 'FeatureCollection', features: [] };

const ha = (n: number) => `${n.toLocaleString(undefined, { maximumFractionDigits: n < 10 ? 2 : 0 })} ha`;

/**
 * The land a campaign has mapped: the map by class, what it adds up to, how
 * much has been checked on the ground, and the files to take away.
 *
 * The filters narrow the map and the download alike, so what is downloaded is
 * what was on screen.
 */
export default function CampaignLand({ campaign, summary, areas, formats }: Props) {
    const [areaId, setAreaId] = useState<number | null>(areas.find((a) => a.features > 0)?.id ?? areas[0]?.id ?? null);
    const [loaded, setLoaded] = useState<GeoJSON.FeatureCollection>(EMPTY);
    const [loadedFor, setLoadedFor] = useState<number | null>(null);
    const [hidden, setHidden] = useState<number[]>([]);
    const [method, setMethod] = useState<Method>('');
    const [verification, setVerification] = useState<string[]>([]);
    const [picked, setPicked] = useState<Record<string, unknown> | null>(null);
    const [format, setFormat] = useState(formats[0]?.key ?? 'geojson');

    const area = areas.find((a) => a.id === areaId) ?? null;
    const loading = areaId !== null && loadedFor !== areaId;

    useEffect(() => {
        if (areaId === null) {
            return;
        }

        const controller = new AbortController();
        fetch(`/client/campaigns/${String(campaign.id)}/land/${String(areaId)}/features.json`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => (response.ok ? (response.json() as Promise<GeoJSON.FeatureCollection>) : EMPTY))
            .then((collection) => {
                setLoaded(collection);
                setLoadedFor(areaId);
            })
            .catch(() => undefined);

        return () => {
            controller.abort();
        };
    }, [areaId, campaign.id]);

    const shown = useMemo<GeoJSON.FeatureCollection>(
        () => ({
            type: 'FeatureCollection',
            features: loaded.features.filter((feature) => {
                const p = feature.properties ?? {};

                return (
                    !hidden.includes(Number(p.classId)) &&
                    (method === '' || p.method === method) &&
                    (verification.length === 0 || verification.includes(String(p.verification)))
                );
            }),
        }),
        [loaded, hidden, method, verification],
    );

    const imagery = useMemo<MapArchive | null>(
        () => (area?.imagery == null ? null : { url: new URL(area.imagery.url, window.location.href).toString(), archive: null }),
        [area],
    );

    // Memoised: the map rebuilds when its centre changes identity.
    const centre = useMemo<[number, number]>(
        () => (area === null ? [8.5, 9] : [(area.bounds[0] + area.bounds[2]) / 2, (area.bounds[1] + area.bounds[3]) / 2]),
        [area],
    );

    const downloadUrl = useMemo(() => {
        const query = new URLSearchParams({ format });
        // Every class unless some are switched off, so a class added later is
        // not quietly missing from a download asked for as "everything".
        if (hidden.length > 0) {
            summary.classes
                .filter((c) => !hidden.includes(c.id))
                .forEach((c) => {
                    query.append('classes[]', String(c.id));
                });
        }
        if (method !== '') {
            query.set('method', method);
        }
        verification.forEach((v) => {
            query.append('verification[]', v);
        });
        if (areaId !== null && areas.length > 1) {
            query.set('area', String(areaId));
        }

        return `/client/campaigns/${String(campaign.id)}/land/export?${query.toString()}`;
    }, [format, hidden, method, verification, areaId, areas.length, summary.classes, campaign.id]);

    const v = summary.verification;

    return (
        <ClientShell current="campaigns">
            <Head title={`Land · ${campaign.name}`} />

            <header className="mt-8 flex flex-wrap items-end justify-between gap-4 border-b border-rule pb-3">
                <div>
                    <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                        <Link href={`/client/campaigns/${String(campaign.id)}`} className="underline underline-offset-2">
                            {campaign.name}
                        </Link>
                        <span className="numeric-mono ml-2 text-faint">{campaign.code}</span>
                    </p>
                    <h1 className="font-display text-display-l text-ink">Land and natural features</h1>
                </div>
                <a href={`/client/campaigns/${String(campaign.id)}/land/report.pdf`} className="text-ui text-gold underline underline-offset-2">
                    Download the area report (PDF)
                </a>
            </header>

            <dl className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[
                    ['Features', summary.totals.features.toLocaleString()],
                    ['Area mapped', ha(summary.totals.areaHa)],
                    ['Rivers, roads and tracks', `${summary.totals.lengthKm.toLocaleString(undefined, { maximumFractionDigits: 1 })} km`],
                    ['Checked on the ground', `${v.checksDone.toLocaleString()} of ${v.target.toLocaleString()}`],
                ].map(([label, value]) => (
                    <div key={label} className="rounded-card border border-rule bg-raised p-4">
                        <dt className="text-label text-muted">{label}</dt>
                        <dd className="numeric-mono mt-1 text-[1.4rem] font-semibold text-ink">{value}</dd>
                    </div>
                ))}
            </dl>

            <section className="mt-6 grid gap-5 lg:grid-cols-[1fr_320px]">
                <div className="relative h-[62dvh] min-h-[420px] overflow-hidden rounded-card border border-rule">
                    {area === null ? (
                        <p className="flex h-full items-center justify-center text-ui text-muted">No ground in this campaign yet.</p>
                    ) : (
                        <AreaMap
                            key={area.id}
                            pack={null}
                            imagery={imagery}
                            basemap={{ basemap: imagery === null ? 'street' : 'satellite', opacity: 1 }}
                            assignedH3=""
                            centre={centre}
                            position={null}
                            features={shown}
                            vertices={[]}
                            kind={null}
                            colour="#4BB8B0"
                            tapping={false}
                            onAddVertex={() => undefined}
                            onMoveVertex={() => undefined}
                            onPickFeature={setPicked}
                            bare
                            bounds={area.bounds}
                        />
                    )}
                    {loading && (
                        <p className="absolute top-3 left-3 rounded-full bg-raised px-3 py-1 text-label text-muted shadow-card">Loading the map…</p>
                    )}
                    {picked !== null && (
                        <div className="absolute bottom-3 left-3 max-w-[280px] rounded-card bg-raised p-3 text-ui shadow-card">
                            <p className="font-extrabold">{String(picked.label)}</p>
                            {picked.areaHa != null && <p className="numeric-mono text-muted">{ha(Number(picked.areaHa))}</p>}
                            {picked.areaHa == null && picked.lengthM != null && (
                                <p className="numeric-mono text-muted">{(Number(picked.lengthM) / 1000).toFixed(2)} km</p>
                            )}
                            <p className="text-label text-muted">
                                {picked.verification === 'verified' ? 'Checked on the ground' : 'Not yet checked on the ground'} ·{' '}
                                {picked.method === 'field' ? 'recorded in the field' : 'drawn from imagery'} · {String(picked.captured)}
                            </p>
                        </div>
                    )}
                    {area?.imagery?.captured != null && (
                        <p className="pointer-events-none absolute right-2 bottom-1.5 rounded-sm bg-raised/80 px-1.5 text-[0.65rem] text-muted">
                            Sentinel-2 · {area.imagery.captured}
                        </p>
                    )}
                </div>

                <aside className="flex flex-col gap-5">
                    {areas.length > 1 && (
                        <label className="flex flex-col gap-1 text-label text-muted">
                            Mandate
                            <select
                                value={areaId ?? ''}
                                onChange={(e) => {
                                    setPicked(null);
                                    setAreaId(Number(e.target.value));
                                }}
                                className="rounded-sm border border-rule bg-raised px-3 py-2 text-ui text-ink"
                            >
                                {areas.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name} ({a.features.toLocaleString()})
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}

                    <fieldset>
                        <legend className="text-label font-semibold text-muted">Classes</legend>
                        <ul className="mt-2 flex flex-col gap-1">
                            {summary.classes
                                .filter((c) => c.features > 0)
                                .map((c) => (
                                    <li key={c.id}>
                                        <label className="flex cursor-pointer items-center gap-2 text-ui">
                                            <input
                                                type="checkbox"
                                                checked={!hidden.includes(c.id)}
                                                onChange={() => {
                                                    setHidden((h) => (h.includes(c.id) ? h.filter((x) => x !== c.id) : [...h, c.id]));
                                                }}
                                            />
                                            <span className="size-3 shrink-0 rounded-[3px]" style={{ background: c.colour }} />
                                            <span className="flex-1">{c.label}</span>
                                            <span className="numeric-mono text-label text-muted">
                                                {c.geometryType === 'polygon'
                                                    ? ha(c.areaHa)
                                                    : c.geometryType === 'line'
                                                      ? `${c.lengthKm.toFixed(1)} km`
                                                      : c.features.toLocaleString()}
                                            </span>
                                        </label>
                                    </li>
                                ))}
                        </ul>
                    </fieldset>

                    <fieldset>
                        <legend className="text-label font-semibold text-muted">How it was recorded</legend>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {(
                                [
                                    ['', 'All'],
                                    ['field', 'In the field'],
                                    ['desk', 'From imagery or a file'],
                                ] as const
                            ).map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => {
                                        setMethod(key);
                                    }}
                                    className={cx(
                                        'rounded-full border px-3 py-1 text-label font-semibold',
                                        method === key ? 'border-ink bg-ink text-inverse' : 'border-rule text-muted hover:text-ink',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="text-label font-semibold text-muted">Ground check</legend>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {VERIFICATION.map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => {
                                        setVerification((list) => (list.includes(key) ? list.filter((x) => x !== key) : [...list, key]));
                                    }}
                                    className={cx(
                                        'rounded-full border px-3 py-1 text-label font-semibold',
                                        verification.includes(key) ? 'border-ink bg-ink text-inverse' : 'border-rule text-muted hover:text-ink',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <div className="rounded-card border border-rule bg-raised p-4">
                        <p className="text-ui font-extrabold text-ink">Download</p>
                        <p className="mt-1 text-label text-muted">
                            What the filters show{areas.length > 1 ? ', for this mandate' : ''}, at full precision, with a data dictionary.
                        </p>
                        <div className="mt-3 flex gap-2">
                            <select
                                value={format}
                                onChange={(e) => {
                                    setFormat(e.target.value);
                                }}
                                aria-label="Format"
                                className="flex-1 rounded-sm border border-rule bg-raised px-3 py-2 text-ui text-ink"
                            >
                                {formats.map((f) => (
                                    <option key={f.key} value={f.key}>
                                        {f.label}
                                    </option>
                                ))}
                            </select>
                            {summary.classes.every((c) => hidden.includes(c.id) || c.features === 0) ? (
                                <span className="rounded-full bg-sunken px-4 py-2 text-ui font-extrabold text-muted">Download</span>
                            ) : (
                                <a href={downloadUrl} className="rounded-full bg-gold-dark px-4 py-2 text-ui font-extrabold text-on-accent hover:bg-gold">
                                    Download
                                </a>
                            )}
                        </div>
                    </div>
                </aside>
            </section>

            <section className="mt-8 rounded-card border border-rule bg-raised p-5">
                <h2 className="font-display text-body font-extrabold text-ink">Checked on the ground</h2>
                <p className="mt-1 max-w-[70ch] text-ui text-muted">
                    Shapes drawn from satellite imagery or imported are checked by an officer standing on them. The campaign
                    samples {v.samplePct}% of the {v.fromDesk.toLocaleString()} drawn that way.
                </p>
                <div className="mt-4 h-2 overflow-hidden rounded-full bg-sunken">
                    <div className="h-full bg-green" style={{ width: `${String(v.progressPct)}%` }} />
                </div>
                <dl className="mt-4 grid grid-cols-2 gap-3 text-ui sm:grid-cols-4">
                    {[
                        ['Checks done', v.checksDone],
                        ['Waiting for an officer', v.checksOpen],
                        ['Confirmed', v.verified],
                        ['Not found', v.rejected],
                    ].map(([label, value]) => (
                        <div key={String(label)}>
                            <dt className="text-label text-muted">{label}</dt>
                            <dd className="numeric-mono font-semibold">{Number(value).toLocaleString()}</dd>
                        </div>
                    ))}
                </dl>
            </section>
        </ClientShell>
    );
}
