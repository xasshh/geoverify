import { useCallback, useEffect, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { AreaMap, type BaseArchive, type DrawKind, type MapArchive } from '@/components/AreaMap';
import { BasemapSwitch } from '@/components/BasemapSwitch';
import { Button } from '@/components/Button';
import { DeskFrame } from '@/components/DeskFrame';
import type { FeatureAttribute } from '@/lib/campaign';
import { csrfToken } from '@/lib/capture';
import type { BasemapChoice } from '@/lib/offline/imagery';
import { cx } from '@/lib/cx';

interface ClassOption {
    id: number;
    key: string;
    label: string;
    geometryType: DrawKind;
    style: { fill?: string; stroke?: string } | null;
    version: number | null;
    attributes: FeatureAttribute[];
}

interface Batch {
    id: number;
    kind: string;
    status: string;
    source: string | null;
    created: number;
    refused: number;
    refusals: Array<{ index: number; message: string }>;
    error: string | null;
    at: string;
}

interface Props {
    area: { id: number; name: string; centre: [number, number] };
    campaign: {
        id: number;
        name: string;
        minMappingUnitHa: number | null;
        samplePct: number;
    };
    pack: {
        url: string;
        minZoom: number;
        maxZoom: number;
        layers: Record<string, number>;
    } | null;
    imagery: {
        url: string;
        captured: string | null;
        licence: string | null;
    } | null;
    classes: ClassOption[];
    batches: Batch[];
}

interface Preview {
    batchId: number;
    total: number;
    geometry: Record<string, number>;
    properties: Record<string, string[]>;
}

type Answer = string | number | boolean | string[];

/**
 * A mandate at the desk: draw over the satellite image, import a client's
 * file, or pre-draw land cover, all for officers to check later.
 *
 * The questions asked here are the ones that can be answered from imagery;
 * those marked as needing the ground are left for the officer.
 */
export default function DeskMandate({ area, campaign, pack, imagery, classes, batches }: Props) {
    const flash = usePage().props.flash.status;
    const errors = usePage().props.errors;

    const [features, setFeatures] = useState<GeoJSON.FeatureCollection>({
        type: 'FeatureCollection',
        features: [],
    });
    const [chosen, setChosen] = useState<ClassOption | null>(null);
    const [vertices, setVertices] = useState<Array<[number, number]>>([]);
    const [answers, setAnswers] = useState<Record<string, Answer>>({});
    const [picked, setPicked] = useState<Record<string, unknown> | null>(null);
    const [basemap, setBasemap] = useState<BasemapChoice>({
        basemap: imagery === null ? 'street' : 'satellite',
        opacity: 1,
    });
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => {
        void fetch(`/desk/mandates/${String(area.id)}/features.geojson`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json() as Promise<GeoJSON.FeatureCollection>)
            .then(setFeatures);
    }, [area.id]);

    useEffect(load, [load]);

    // While a land cover seed is running, look again now and then.
    const running = batches.some((b) => b.status === 'queued' || b.status === 'processing');

    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['batches'], onSuccess: load });
        }, 8000);

        return () => {
            window.clearInterval(timer);
        };
    }, [running, load]);

    const base = useMemo<BaseArchive | null>(
        () =>
            pack === null
                ? null
                : {
                      url: new URL(pack.url, window.location.href).toString(),
                      archive: null,
                      minZoom: pack.minZoom,
                      maxZoom: pack.maxZoom,
                      layers: pack.layers,
                  },
        [pack],
    );
    const satellite = useMemo<MapArchive | null>(
        () =>
            imagery === null
                ? null
                : {
                      url: new URL(imagery.url, window.location.href).toString(),
                      archive: null,
                  },
        [imagery],
    );

    const kind = chosen?.geometryType ?? null;
    const colour = chosen?.style?.fill ?? chosen?.style?.stroke ?? '#4BB8B0';
    const enough = kind === 'point' ? vertices.length === 1 : kind === 'line' ? vertices.length >= 2 : vertices.length >= 3;
    const deskQuestions = chosen?.attributes.filter((a) => !a.field_only) ?? [];

    const cancel = () => {
        setChosen(null);
        setVertices([]);
        setAnswers({});
    };

    const save = () => {
        if (chosen === null || !enough) {
            return;
        }

        const geometry: GeoJSON.Geometry =
            kind === 'point'
                ? {
                      type: 'Point',
                      coordinates: vertices[0] as [number, number],
                  }
                : kind === 'line'
                  ? { type: 'LineString', coordinates: vertices }
                  : {
                        type: 'Polygon',
                        coordinates: [[...vertices, vertices[0] as [number, number]]],
                    };

        setSaving(true);
        router.post(
            `/desk/mandates/${String(area.id)}/features`,
            {
                feature_class_id: chosen.id,
                class_version: chosen.version,
                geometry: geometry as never,
                answers: answers as never,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    cancel();
                    load();
                },
                onFinish: () => {
                    setSaving(false);
                },
            },
        );
    };

    return (
        <DeskFrame title={`${area.name} · ${campaign.name}`} back="/desk">
            <Head title={`Desk · ${area.name}`} />
            <div className="grid h-[calc(100dvh-57px)] grid-cols-1 lg:grid-cols-[1fr_380px]">
                <div className="relative min-h-[50dvh]">
                    {base === null && satellite === null ? (
                        <div className="flex h-full items-center justify-center p-8 text-center text-ui text-muted">
                            This ground has no map yet. Build its satellite view from Mandates in administration.
                        </div>
                    ) : (
                        <AreaMap
                            pack={base}
                            imagery={satellite}
                            basemap={basemap}
                            assignedH3=""
                            centre={area.centre}
                            position={null}
                            features={features}
                            vertices={vertices}
                            kind={kind}
                            colour={colour}
                            tapping={chosen !== null}
                            onAddVertex={(at) => {
                                setVertices((current) => (kind === 'point' ? [at] : [...current, at]));
                            }}
                            onMoveVertex={(index, to) => {
                                setVertices((current) => current.map((v, i) => (i === index ? to : v)));
                            }}
                            onPickFeature={setPicked}
                        />
                    )}
                    {imagery !== null && base !== null && (
                        <BasemapSwitch
                            choice={basemap}
                            captured={imagery.captured}
                            onChange={setBasemap}
                            className="absolute top-3 right-3 w-[12rem]"
                        />
                    )}
                    {imagery?.licence != null && (
                        <p className="pointer-events-none absolute bottom-1.5 left-2 rounded-sm bg-raised/80 px-1.5 text-[0.65rem] text-muted">
                            {imagery.licence} {imagery.captured !== null && `· ${imagery.captured}`}
                        </p>
                    )}
                </div>

                <aside className="overflow-y-auto border-l border-rule bg-raised p-4">
                    {flash !== null && (
                        <p role="status" className="mb-3 rounded-sm bg-green-soft px-3 py-2 text-ui text-ink">
                            {flash}
                        </p>
                    )}
                    {Object.entries(errors).map(([key, message]) => (
                        <p key={key} role="alert" className="mb-3 rounded-sm bg-alert-soft px-3 py-2 text-ui text-alert">
                            {message}
                        </p>
                    ))}

                    {chosen === null ? (
                        <section>
                            <p className="text-ui font-extrabold">Draw a feature</p>
                            <div className="mt-2 grid grid-cols-2 gap-1.5">
                                {classes.map((option) => (
                                    <button
                                        key={option.id}
                                        type="button"
                                        onClick={() => {
                                            setChosen(option);
                                            setPicked(null);
                                        }}
                                        className="flex min-h-[40px] items-center gap-2 rounded-sm border border-rule px-2.5 text-left text-label font-semibold"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cx(
                                                'size-3 shrink-0 border',
                                                option.geometryType === 'point' ? 'rounded-full' : 'rounded-[2px]',
                                            )}
                                            style={{
                                                backgroundColor: option.style?.fill ?? option.style?.stroke,
                                                borderColor: option.style?.stroke,
                                            }}
                                        />
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </section>
                    ) : (
                        <section className="flex flex-col gap-3">
                            <p className="text-ui font-extrabold">{chosen.label}</p>
                            <p className="text-label text-muted">
                                {kind === 'point' ? 'Click where it is.' : 'Click the corners. Drag a corner to move it.'} {vertices.length}{' '}
                                placed.
                            </p>
                            {deskQuestions.map((attribute) => (
                                <label key={attribute.key} className="flex flex-col gap-1 text-label font-semibold text-muted">
                                    {attribute.label}
                                    {attribute.required && ' *'}
                                    {attribute.type === 'select' ? (
                                        <select
                                            value={typeof answers[attribute.key] === 'string' ? (answers[attribute.key] as string) : ''}
                                            onChange={(e) => {
                                                setAnswers({
                                                    ...answers,
                                                    [attribute.key]: e.target.value,
                                                });
                                            }}
                                            className="h-10 rounded-sm border border-rule-strong px-2 text-ui font-normal text-ink"
                                        >
                                            <option value="">Choose</option>
                                            {(attribute.options ?? []).map((o) => (
                                                <option key={o}>{o}</option>
                                            ))}
                                        </select>
                                    ) : attribute.type === 'boolean' ? (
                                        <select
                                            value={answers[attribute.key] === undefined ? '' : String(answers[attribute.key])}
                                            onChange={(e) => {
                                                setAnswers({
                                                    ...answers,
                                                    [attribute.key]: e.target.value === 'true',
                                                });
                                            }}
                                            className="h-10 rounded-sm border border-rule-strong px-2 text-ui font-normal text-ink"
                                        >
                                            <option value="">Choose</option>
                                            <option value="true">Yes</option>
                                            <option value="false">No</option>
                                        </select>
                                    ) : (
                                        <input
                                            type={attribute.type === 'number' ? 'number' : attribute.type === 'date' ? 'date' : 'text'}
                                            value={
                                                typeof answers[attribute.key] === 'string' || typeof answers[attribute.key] === 'number'
                                                    ? String(answers[attribute.key])
                                                    : ''
                                            }
                                            onChange={(e) => {
                                                setAnswers({
                                                    ...answers,
                                                    [attribute.key]: attribute.type === 'number' ? Number(e.target.value) : e.target.value,
                                                });
                                            }}
                                            className="h-10 rounded-sm border border-rule-strong px-2 text-ui font-normal text-ink"
                                        />
                                    )}
                                </label>
                            ))}
                            <div className="flex gap-2">
                                <Button
                                    variant="quiet"
                                    onClick={() => {
                                        setVertices((v) => v.slice(0, -1));
                                    }}
                                    disabled={vertices.length === 0}
                                >
                                    Undo
                                </Button>
                                <Button variant="quiet" onClick={cancel}>
                                    Cancel
                                </Button>
                                <Button variant="primary" onClick={save} busy={saving} disabled={!enough}>
                                    Save
                                </Button>
                            </div>
                        </section>
                    )}

                    {picked !== null && chosen === null && (
                        <section className="mt-5 rounded-sm border border-rule p-3">
                            <p className="text-ui font-extrabold">{String(picked.label)}</p>
                            <p className="mt-0.5 text-label text-muted">
                                {String(picked.method).replace('_', ' ')} · {String(picked.verification)}
                                {picked.areaHa != null && ` · ${Number(picked.areaHa).toFixed(2)} ha`}
                                {picked.lengthM != null && ` · ${Number(picked.lengthM).toFixed(0)} m`}
                                {picked.sent === true && ' · sent for checking'}
                            </p>
                            <button
                                type="button"
                                onClick={() => {
                                    router.post(
                                        `/desk/features/${String(picked.uuid)}/withdraw`,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setPicked(null);
                                                load();
                                            },
                                        },
                                    );
                                }}
                                className="mt-2 text-label text-alert underline underline-offset-2"
                            >
                                Withdraw (kept on record, off the map)
                            </button>
                        </section>
                    )}

                    <ImportPanel areaId={area.id} classes={classes} onDone={load} />

                    <section className="mt-6 border-t border-rule pt-4">
                        <p className="text-ui font-extrabold">Pre-draw land cover</p>
                        <p className="mt-1 text-label text-muted">
                            Forest, farmland, grassland, water, wetland, bare land and settlements from ESA WorldCover 10 m, pieces under{' '}
                            {campaign.minMappingUnitHa ?? 0.5} ha left out. Officers then check a {campaign.samplePct}% sample.
                        </p>
                        <div className="mt-2">
                            <Button
                                disabled={running}
                                onClick={() => {
                                    router.post(`/desk/mandates/${String(area.id)}/landcover`, {}, { preserveScroll: true });
                                }}
                            >
                                {running ? 'Drawing land cover' : 'Draw land cover'}
                            </Button>
                        </div>
                    </section>

                    {batches.length > 0 && (
                        <section className="mt-6 border-t border-rule pt-4">
                            <p className="text-ui font-extrabold">Recent imports</p>
                            <ul className="mt-2 flex flex-col gap-2">
                                {batches.map((batch) => (
                                    <li key={batch.id} className="text-label">
                                        <span className="font-semibold">{batch.source ?? batch.kind}</span>{' '}
                                        <span className={cx(batch.status === 'failed' ? 'text-alert' : 'text-muted')}>
                                            {batch.status === 'done'
                                                ? `${String(batch.created)} added${batch.refused > 0 ? `, ${String(batch.refused)} refused` : ''}`
                                                : batch.status}
                                        </span>
                                        {batch.error !== null && <span className="block text-muted">{batch.error}</span>}
                                        {batch.refusals.map((r) => (
                                            <span key={r.index} className="block text-faint">
                                                #{r.index}: {r.message}
                                            </span>
                                        ))}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </aside>
            </div>
        </DeskFrame>
    );
}

/** Upload a client's file, see what is in it, map it to classes, commit. */
function ImportPanel({ areaId, classes, onDone }: { areaId: number; classes: ClassOption[]; onDone: () => void }) {
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [mode, setMode] = useState<'single' | 'property'>('single');
    const [classId, setClassId] = useState<number | null>(null);
    const [property, setProperty] = useState<string>('');
    const [values, setValues] = useState<Record<string, number | null>>({});

    const upload = async (file: File) => {
        setBusy(true);
        setError(null);
        const form = new FormData();
        form.append('file', file);

        try {
            const response = await fetch(`/desk/mandates/${String(areaId)}/import`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: form,
            });
            const body = (await response.json()) as Preview & {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok) {
                setError(Object.values(body.errors ?? {}).flat()[0] ?? body.message ?? 'The file could not be read.');

                return;
            }

            setPreview(body);
            setProperty(Object.keys(body.properties)[0] ?? '');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="mt-6 border-t border-rule pt-4">
            <p className="text-ui font-extrabold">Import a file</p>
            <p className="mt-1 text-label text-muted">
                GeoJSON, KML, a zipped Shapefile or a GeoPackage. Every shape meets the same rules as one drawn by hand; if any is refused,
                none is kept.
            </p>
            {preview === null ? (
                <input
                    type="file"
                    accept=".geojson,.json,.kml,.zip,.gpkg"
                    disabled={busy}
                    onChange={(e) => {
                        const file = e.target.files?.[0];

                        if (file !== undefined) {
                            void upload(file);
                        }
                    }}
                    className="mt-2 text-label"
                />
            ) : (
                <div className="mt-2 flex flex-col gap-2 text-label">
                    <p>
                        {preview.total} shapes:{' '}
                        {Object.entries(preview.geometry)
                            .map(([k, n]) => `${String(n)} ${k}`)
                            .join(', ')}
                    </p>
                    <div className="flex gap-3">
                        <label className="flex items-center gap-1.5">
                            <input
                                type="radio"
                                checked={mode === 'single'}
                                onChange={() => {
                                    setMode('single');
                                }}
                            />
                            One class for all
                        </label>
                        <label className="flex items-center gap-1.5">
                            <input
                                type="radio"
                                checked={mode === 'property'}
                                disabled={Object.keys(preview.properties).length === 0}
                                onChange={() => {
                                    setMode('property');
                                }}
                            />
                            By a property
                        </label>
                    </div>
                    {mode === 'single' ? (
                        <select
                            value={classId ?? ''}
                            onChange={(e) => {
                                setClassId(e.target.value === '' ? null : Number(e.target.value));
                            }}
                            className="h-10 rounded-sm border border-rule-strong px-2"
                        >
                            <option value="">Choose a class</option>
                            {classes.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <>
                            <select
                                value={property}
                                onChange={(e) => {
                                    setProperty(e.target.value);
                                    setValues({});
                                }}
                                className="h-10 rounded-sm border border-rule-strong px-2"
                            >
                                {Object.keys(preview.properties).map((p) => (
                                    <option key={p}>{p}</option>
                                ))}
                            </select>
                            {(preview.properties[property] ?? []).map((value) => (
                                <label key={value} className="flex items-center gap-2">
                                    <span className="w-28 truncate">{value}</span>
                                    <select
                                        value={values[value] ?? ''}
                                        onChange={(e) => {
                                            setValues({
                                                ...values,
                                                [value]: e.target.value === '' ? null : Number(e.target.value),
                                            });
                                        }}
                                        className="h-9 flex-1 rounded-sm border border-rule-strong px-2"
                                    >
                                        <option value="">Leave out</option>
                                        {classes.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            ))}
                        </>
                    )}
                    <div className="flex gap-2">
                        <Button
                            variant="quiet"
                            onClick={() => {
                                setPreview(null);
                            }}
                        >
                            Start again
                        </Button>
                        <Button
                            variant="primary"
                            busy={busy}
                            disabled={mode === 'single' ? classId === null : property === ''}
                            onClick={() => {
                                setBusy(true);
                                router.post(
                                    `/desk/imports/${String(preview.batchId)}/commit`,
                                    mode === 'single' ? { class_id: classId } : { property, values: values as never },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setPreview(null);
                                            onDone();
                                        },
                                        onFinish: () => {
                                            setBusy(false);
                                        },
                                    },
                                );
                            }}
                        >
                            Import
                        </Button>
                    </div>
                </div>
            )}
            {error !== null && <p className="mt-2 text-label text-alert">{error}</p>}
        </section>
    );
}
