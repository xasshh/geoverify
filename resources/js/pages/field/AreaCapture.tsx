import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AreaMap, type BaseArchive, type DrawKind, type MapArchive } from '@/components/AreaMap';
import { openPack } from '@/lib/offline/pack';
import { openImagery } from '@/lib/offline/imagery';
import { BasemapSwitch } from '@/components/BasemapSwitch';
import { Button } from '@/components/Button';
import { SyncIndicator } from '@/components/SyncIndicator';
import { holdAreaPhotograph } from '@/lib/offline/areaPhotos';
import { useImagery } from '@/lib/offline/useImagery';
import { useOfflineQueue } from '@/lib/offline/useOfflineQueue';
import { usePack } from '@/lib/offline/usePack';
import { useTrace, type Fix } from '@/lib/geolocation';
import { sendFixes, startSession, uuid7 } from '@/lib/capture';
import type { FeatureAttribute } from '@/lib/campaign';
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

interface Props {
    assignmentId: number;
    cell: { id: number; coverageAreaId: number; h3: string; mandate: string; centre: [number, number] };
    campaign: { id: number; name: string; minMappingUnitHa: number | null; maxAccuracyM: number; buildings: boolean };
    classes: ClassOption[];
    features: GeoJSON.FeatureCollection;
    tasks: CheckTask[];
}

/** A feature drawn at the desk that this officer was sent to check. */
interface CheckTask {
    id: number;
    featureUuid: string;
    classId: number;
    label: string;
    method: string;
    geometry: GeoJSON.Geometry;
    at: [number, number];
}

type Step = 'pick' | 'check' | 'draw' | 'answer';
type Answer = string | number | boolean | string[];

/** In walk mode, a corner is dropped each time the officer has gone this far. */
const WALK_SPACING_M = 8;

/**
 * Metres between two points, for the figures on screen only.
 *
 * Display, not record: the area and length kept are computed by PostGIS when
 * the capture lands, from the same corners.
 */
function metres(a: [number, number], b: [number, number]): number {
    const rad = Math.PI / 180;
    const x = (b[0] - a[0]) * rad * Math.cos(((a[1] + b[1]) / 2) * rad);
    const y = (b[1] - a[1]) * rad;

    return Math.hypot(x, y) * 6_371_000;
}

function lengthOf(vertices: Array<[number, number]>, closed: boolean): number {
    let total = 0;

    for (let i = 1; i < vertices.length; i++) {
        total += metres(vertices[i - 1] as [number, number], vertices[i] as [number, number]);
    }

    if (closed && vertices.length > 2) {
        total += metres(vertices[vertices.length - 1] as [number, number], vertices[0] as [number, number]);
    }

    return total;
}

/** Shoelace on a local flat projection: good to a few percent at field scale. */
function hectaresOf(vertices: Array<[number, number]>): number {
    if (vertices.length < 3) {
        return 0;
    }

    const origin = vertices[0] as [number, number];
    const rad = Math.PI / 180;
    const k = Math.cos(origin[1] * rad) * 6_371_000 * rad;
    const pts = vertices.map(([x, y]) => [(x - origin[0]) * k, (y - origin[1]) * 6_371_000 * rad] as const);
    let sum = 0;

    for (let i = 0; i < pts.length; i++) {
        const [x1, y1] = pts[i] as readonly [number, number];
        const [x2, y2] = pts[(i + 1) % pts.length] as readonly [number, number];
        sum += x1 * y2 - x2 * y1;
    }

    return Math.abs(sum) / 2 / 10_000;
}

/**
 * Area capture: the land, water and the things on it.
 *
 * Pick what you are recording, draw it (tap the corners, walk the edge, or
 * stand on it for a point), answer its questions, photograph it. It is queued
 * on the phone like a building and synced when there is signal.
 */
export default function AreaCapture({ assignmentId, cell, campaign, classes, features, tasks }: Props) {
    const queue = useOfflineQueue();
    const pack = usePack(cell.coverageAreaId);
    const imagery = useImagery(cell.coverageAreaId);
    const trace = useTrace(true);
    const { takeFixes } = trace;

    // The archives on the phone, opened for the map.
    const base = useMemo<BaseArchive | null>(() => {
        const local = pack.pack;
        const open = local === null ? null : openPack(local);

        return local === null || open === null ? null : { ...open, minZoom: local.minZoom, maxZoom: local.maxZoom, layers: local.layers };
    }, [pack.pack]);
    const satellite = useMemo<MapArchive | null>(() => (imagery.image === null ? null : openImagery(imagery.image)), [imagery.image]);

    const [step, setStep] = useState<Step>('pick');
    // The check under way, and the ones finished on this phone today.
    const [task, setTask] = useState<CheckTask | null>(null);
    const [finished, setFinished] = useState<number[]>([]);
    const openTasks = tasks.filter((t) => !finished.includes(t.id));
    const [chosen, setChosen] = useState<ClassOption | null>(null);
    const [vertices, setVertices] = useState<Array<[number, number]>>([]);
    const [walking, setWalking] = useState(false);
    const [rejectedFixes, setRejectedFixes] = useState(0);
    const [answers, setAnswers] = useState<Record<string, Answer>>({});
    const [notes, setNotes] = useState('');
    const [photos, setPhotos] = useState<Array<{ file: File; bearing: number | null }>>([]);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState<GeoJSON.Feature[]>([]);
    const [flash, setFlash] = useState<string | null>(null);
    const [usedWalk, setUsedWalk] = useState(false);

    // One field session per visit, as on the building screen: the walk is
    // its position fixes, which is what the confidence score reads.
    const sessionUuid = useRef(uuid7());
    const [sessionId, setSessionId] = useState<number | null>(null);
    const sessionRequested = useRef(false);

    useEffect(() => {
        if (sessionRequested.current) {
            return;
        }

        sessionRequested.current = true;
        void startSession({ client_uuid: sessionUuid.current, assignment_id: assignmentId })
            .then((session) => {
                setSessionId(session.id);
            })
            .catch(() => {
                // Captures still work; the score will say the walk was not seen.
            });
    }, [assignmentId]);

    const flushFixes = useCallback(() => {
        if (sessionId === null) {
            return;
        }

        const batch = takeFixes();

        if (batch.length > 0) {
            void sendFixes(sessionId, batch).catch(() => undefined);
        }
    }, [sessionId, takeFixes]);

    useEffect(() => {
        const timer = window.setInterval(flushFixes, 20_000);

        return () => {
            window.clearInterval(timer);
        };
    }, [flushFixes]);

    // The compass, for the direction each photograph faces.
    const heading = useRef<number | null>(null);

    useEffect(() => {
        const read = (event: DeviceOrientationEvent) => {
            const compass = (event as DeviceOrientationEvent & { webkitCompassHeading?: number }).webkitCompassHeading;
            heading.current = compass ?? (event.alpha === null ? null : (360 - event.alpha) % 360);
        };

        window.addEventListener('deviceorientation', read);

        return () => {
            window.removeEventListener('deviceorientation', read);
        };
    }, []);

    // Walk mode: a corner each time the officer has gone far enough, from a
    // fix good enough to trust. Worse fixes are counted, not used. Its own
    // watch, so every fix the receiver produces is considered, not only the
    // ones that happen to re-render the screen.
    const position = trace.current;

    useEffect(() => {
        if (!walking || !('geolocation' in navigator)) {
            return;
        }

        const watch = navigator.geolocation.watchPosition(
            (reading) => {
                if (reading.coords.accuracy > campaign.maxAccuracyM) {
                    setRejectedFixes((n) => n + 1);

                    return;
                }

                const here: [number, number] = [reading.coords.longitude, reading.coords.latitude];

                setVertices((current) => {
                    const last = current[current.length - 1];

                    return last === undefined || metres(last, here) >= WALK_SPACING_M ? [...current, here] : current;
                });
            },
            () => undefined,
            { enableHighAccuracy: true, maximumAge: 0, timeout: 20_000 },
        );

        return () => {
            navigator.geolocation.clearWatch(watch);
        };
    }, [walking, campaign.maxAccuracyM]);

    const colour = chosen?.style?.fill ?? chosen?.style?.stroke ?? '#4BB8B0';
    const kind = chosen?.geometryType ?? null;

    const grouped = useMemo(() => {
        const groups: Array<[string, DrawKind, ClassOption[]]> = [
            ['Areas', 'polygon', []],
            ['Lines', 'line', []],
            ['Points', 'point', []],
        ];

        for (const option of classes) {
            groups.find(([, k]) => k === option.geometryType)?.[2].push(option);
        }

        return groups.filter(([, , list]) => list.length > 0);
    }, [classes]);

    const recorded = useMemo<GeoJSON.FeatureCollection>(
        () => ({ type: 'FeatureCollection', features: [...features.features, ...saved] }),
        [features, saved],
    );

    /** The desk's shape as corners the officer can adjust. */
    const cornersOf = (geometry: GeoJSON.Geometry): Array<[number, number]> => {
        if (geometry.type === 'Point') {
            return [geometry.coordinates as [number, number]];
        }

        if (geometry.type === 'LineString') {
            return geometry.coordinates as Array<[number, number]>;
        }

        const ring = (geometry.type === 'Polygon' ? geometry.coordinates[0] : geometry.type === 'MultiPolygon' ? geometry.coordinates[0]?.[0] : undefined) ?? [];

        return (ring as Array<[number, number]>).slice(0, -1);
    };

    const startCheck = (next: CheckTask) => {
        setTask(next);
        setChosen(classes.find((c) => c.id === next.classId) ?? null);
        setVertices(cornersOf(next.geometry));
        setFlash(null);
        setError(null);
        setStep('check');
    };

    /** Not there, or not checkable today: no shape, so its own mutation. */
    const recordOutcome = async (outcome: 'rejected' | 'needs_revisit'): Promise<void> => {
        if (task === null) {
            return;
        }

        await queue.record('area_feature_outcome', { client_uuid: uuid7(), feature_uuid: task.featureUuid, outcome, notes: notes.trim() === '' ? null : notes.trim() });
        setFinished((current) => [...current, task.id]);
        setFlash(outcome === 'rejected' ? `${task.label} marked as not there.` : `${task.label} marked to revisit.`);
        reset();
    };

    const reset = () => {
        setTask(null);
        setStep('pick');
        setChosen(null);
        setVertices([]);
        setWalking(false);
        setRejectedFixes(0);
        setAnswers({});
        setNotes('');
        setPhotos([]);
        setError(null);
        setUsedWalk(false);
    };

    const hereAsVertex = (fix: Fix | null) => {
        if (fix === null) {
            setError('Waiting for a GPS position.');

            return;
        }

        if (fix.accuracy_m !== null && fix.accuracy_m > campaign.maxAccuracyM) {
            setError(`GPS is ±${fix.accuracy_m.toFixed(0)} m. This campaign needs ±${String(campaign.maxAccuracyM)} m or better. Move into the open.`);

            return;
        }

        setError(null);
        const here: [number, number] = [fix.longitude, fix.latitude];
        setVertices((current) => (kind === 'point' ? [here] : [...current, here]));
    };

    const enough = kind === 'point' ? vertices.length === 1 : kind === 'line' ? vertices.length >= 2 : vertices.length >= 3;
    const hectares = kind === 'polygon' ? hectaresOf(vertices) : 0;
    const belowMinimum = kind === 'polygon' && campaign.minMappingUnitHa !== null && enough && hectares < campaign.minMappingUnitHa;

    const geometry = (): GeoJSON.Geometry =>
        kind === 'point'
            ? { type: 'Point', coordinates: vertices[0] as [number, number] }
            : kind === 'line'
              ? { type: 'LineString', coordinates: vertices }
              : { type: 'Polygon', coordinates: [[...vertices, vertices[0] as [number, number]]] };

    const save = async (): Promise<void> => {
        if (chosen === null || !enough) {
            return;
        }

        // Required questions, checked here so the officer fixes them on the
        // spot; the server checks again against the same form version.
        const missing = chosen.attributes.filter((a) => a.required && (answers[a.key] === undefined || answers[a.key] === '' || (Array.isArray(answers[a.key]) && (answers[a.key] as string[]).length === 0)));

        if (missing.length > 0) {
            setError(`Answer: ${missing.map((a) => a.label).join(', ')}.`);

            return;
        }

        const revisionUuid = uuid7();
        const featureUuid = task?.featureUuid ?? uuid7();
        const shape = geometry();
        const fix = trace.current;

        flushFixes();

        await queue.record('area_feature', {
            client_uuid: revisionUuid,
            feature_uuid: featureUuid,
            feature_class_id: chosen.id,
            class_version: chosen.version,
            // Checking a desk feature is a verification, however it was redrawn.
            capture_method: task !== null ? 'field_verified' : usedWalk ? 'field_walked' : 'field_drawn',
            assignment_id: assignmentId,
            geometry: shape,
            answers,
            notes: notes.trim() === '' ? null : notes.trim(),
            gps_accuracy_m: fix?.accuracy_m ?? null,
            field_session_client_uuid: sessionUuid.current,
            captured_at: new Date().toISOString(),
        });

        for (const photo of photos) {
            await holdAreaPhotograph(
                revisionUuid,
                photo.file,
                fix === null ? null : { longitude: fix.longitude, latitude: fix.latitude },
                photo.bearing,
            );
        }

        setSaved((current) => [
            ...current,
            { type: 'Feature', geometry: shape, properties: { uuid: featureUuid, label: chosen.label, colour, pending: true } },
        ]);
        if (task !== null) {
            setFinished((current) => [...current, task.id]);
        }

        setFlash(`${chosen.label} ${task !== null ? 'checked' : 'saved'} on the phone. It syncs when there is signal.`);
        reset();
    };

    const setAnswer = (key: string, value: Answer) => {
        setAnswers((current) => ({ ...current, [key]: value }));
    };

    return (
        <div data-mode="daylight" className="flex h-dvh flex-col bg-raised text-ink">
            <Head title="Area capture" />

            <header className="border-b border-rule px-3 pt-2 pb-2">
                <div className="flex items-center gap-2">
                    <Link href="/field" aria-label="Back to today" className="flex size-10 shrink-0 items-center justify-center rounded-full text-ink">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                            <path d="m15 6-6 6 6 6" />
                        </svg>
                    </Link>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-ui font-extrabold">{cell.mandate}</p>
                        <p className="truncate text-label text-muted">{campaign.name}</p>
                    </div>
                </div>
                <div className="mt-1 pl-12">
                    <SyncIndicator
                        connectivity={queue.syncing ? 'syncing' : queue.online ? 'online' : 'offline'}
                        queued={queue.queued + trace.pendingCount}
                        lastSync={queue.lastSyncAt === null ? null : new Date(queue.lastSyncAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        compact
                    />
                </div>
            </header>

            {campaign.buildings && (
                <nav aria-label="Capture mode" className="grid grid-cols-2 gap-1 bg-sunken p-1.5">
                    <Link href={`/field/assignments/${String(assignmentId)}/capture`} className="rounded-[8px] py-2 text-center text-ui font-semibold text-muted">
                        Buildings
                    </Link>
                    <span aria-current="page" className="rounded-[8px] bg-raised py-2 text-center text-ui font-extrabold text-ink shadow-card">
                        Area features
                    </span>
                </nav>
            )}

            <div className="relative min-h-0 flex-1">
                {pack.state === 'installed' && base !== null ? (
                    <AreaMap
                        pack={base}
                        imagery={satellite}
                        basemap={imagery.choice}
                        assignedH3={cell.h3}
                        centre={cell.centre}
                        position={position}
                        features={recorded}
                        vertices={vertices}
                        kind={kind}
                        colour={colour}
                        tapping={step === 'draw' && !walking && kind !== null}
                        focus={task?.at ?? null}
                        onAddVertex={(at) => {
                            setVertices((current) => (kind === 'point' ? [at] : [...current, at]));
                        }}
                        onMoveVertex={(index, to) => {
                            setVertices((current) => current.map((v, i) => (i === index ? to : v)));
                        }}
                    />
                ) : (
                    <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
                        <p className="text-ui text-muted">The offline map for this area is not on this phone yet.</p>
                        <Link href="/field/device" className="rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent">
                            Download the map
                        </Link>
                    </div>
                )}

                {imagery.image !== null && (
                    <BasemapSwitch
                        choice={imagery.choice}
                        captured={imagery.image.captured}
                        onChange={imagery.choose}
                        className="absolute top-2.5 right-2.5 w-[11.5rem]"
                    />
                )}

                {position !== null && (
                    <span className="pointer-events-none absolute top-2.5 left-2.5 rounded-sm bg-surface/85 px-2 py-1 numeric-mono text-mono text-ink backdrop-blur-sm">
                        GPS ±{position.accuracy_m?.toFixed(0) ?? '?'} m
                    </span>
                )}
            </div>

            <section className="max-h-[52dvh] overflow-y-auto border-t border-rule bg-raised px-4 pt-3 pb-4">
                {flash !== null && step === 'pick' && (
                    <p role="status" className="mb-3 rounded-sm bg-green-soft px-3 py-2 text-ui font-semibold text-green">
                        {flash}
                    </p>
                )}

                {step === 'pick' && openTasks.length > 0 && (
                    <div className="mb-4">
                        <p className="text-ui font-extrabold">Sent to check ({openTasks.length})</p>
                        <ul className="mt-1.5 flex flex-col gap-1.5">
                            {openTasks.map((t) => (
                                <li key={t.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            startCheck(t);
                                        }}
                                        className="flex min-h-touch w-full items-center justify-between gap-3 rounded-sm border border-amber/50 bg-amber-soft px-3 text-left text-ui"
                                    >
                                        <span className="font-semibold text-ink">{t.label}</span>
                                        <span className="text-label text-amber-ink">{t.method === 'imported' ? 'from land cover' : 'drawn at the desk'}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {step === 'check' && task !== null && (
                    <div className="flex flex-col gap-2">
                        <p className="text-ui font-extrabold">Check: {task.label}</p>
                        <p className="text-label text-muted">
                            Go to it on the map. Confirm it as drawn, correct its shape or what it is, or say it is not there.
                        </p>
                        <Button
                            variant="primary"
                            size="field"
                            fullWidth
                            onClick={() => {
                                setStep('draw');
                            }}
                        >
                            It is here: check the shape
                        </Button>
                        <label className="flex flex-col gap-1 text-label font-semibold text-muted">
                            It is something else
                            <select
                                value={chosen?.id ?? ''}
                                onChange={(e) => {
                                    const next = classes.find((c) => c.id === Number(e.target.value)) ?? null;
                                    setChosen(next);

                                    // A different kind of shape cannot keep the desk's corners.
                                    if (next !== null && next.geometryType !== chosen?.geometryType) {
                                        setVertices([]);
                                    }

                                    setStep('draw');
                                }}
                                className="min-h-touch rounded-sm border border-rule-strong px-3 text-ui font-normal text-ink"
                            >
                                {classes.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                size="field"
                                onClick={() => {
                                    void recordOutcome('rejected');
                                }}
                            >
                                Not there
                            </Button>
                            <Button
                                size="field"
                                onClick={() => {
                                    void recordOutcome('needs_revisit');
                                }}
                            >
                                Cannot check today
                            </Button>
                        </div>
                        <Button variant="quiet" size="field" onClick={reset}>
                            Back
                        </Button>
                    </div>
                )}

                {step === 'pick' && (
                    <div>
                        <p className="text-ui font-extrabold">What are you recording?</p>
                        {grouped.map(([title, , list]) => (
                            <div key={title} className="mt-3">
                                <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">{title}</p>
                                <div className="mt-1.5 grid grid-cols-2 gap-2">
                                    {list.map((option) => (
                                        <button
                                            key={option.id}
                                            type="button"
                                            onClick={() => {
                                                setChosen(option);
                                                setStep('draw');
                                                setFlash(null);
                                            }}
                                            className="flex min-h-touch-lg items-center gap-2.5 rounded-sm border border-rule px-3 text-left text-ui font-semibold text-ink"
                                        >
                                            <span
                                                aria-hidden="true"
                                                className={cx('size-3.5 shrink-0 border', option.geometryType === 'point' ? 'rounded-full' : 'rounded-[3px]')}
                                                style={{ backgroundColor: option.style?.fill ?? option.style?.stroke, borderColor: option.style?.stroke }}
                                            />
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {step === 'draw' && chosen !== null && (
                    <div>
                        <div className="flex items-baseline justify-between gap-3">
                            <p className="text-ui font-extrabold">{chosen.label}</p>
                            <p className="numeric-mono text-label text-muted">
                                {kind === 'polygon' && `${hectares.toFixed(2)} ha · ${lengthOf(vertices, true).toFixed(0)} m round`}
                                {kind === 'line' && `${lengthOf(vertices, false).toFixed(0)} m`}
                                {kind === 'point' && (vertices.length === 1 ? 'placed' : '')}
                            </p>
                        </div>
                        <p className="mt-1 text-label text-muted">
                            {kind === 'point'
                                ? 'Stand on it and use your position, or tap the map.'
                                : walking
                                  ? `Walking: a corner every ${String(WALK_SPACING_M)} m. ${rejectedFixes > 0 ? `${String(rejectedFixes)} poor fixes skipped.` : ''}`
                                  : 'Tap the map to add corners, drag a corner to move it, or walk the edge.'}
                        </p>
                        {belowMinimum && (
                            <p className="mt-2 text-label text-amber-ink">
                                Smaller than the {String(campaign.minMappingUnitHa)} ha this campaign records.
                            </p>
                        )}
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Button
                                size="field"
                                onClick={() => {
                                    hereAsVertex(trace.current);
                                }}
                            >
                                {kind === 'point' ? 'Use my position' : 'Corner here'}
                            </Button>
                            {kind !== 'point' && (
                                <Button
                                    size="field"
                                    variant={walking ? 'primary' : 'secondary'}
                                    onClick={() => {
                                        setWalking(!walking);
                                        setUsedWalk(true);
                                    }}
                                >
                                    {walking ? 'Stop walking' : 'Walk the edge'}
                                </Button>
                            )}
                            <Button
                                size="field"
                                variant="quiet"
                                disabled={vertices.length === 0}
                                onClick={() => {
                                    setVertices((current) => current.slice(0, -1));
                                }}
                            >
                                Undo
                            </Button>
                        </div>
                        <div className="mt-3 flex gap-2">
                            <Button variant="quiet" size="field" onClick={reset}>
                                Cancel
                            </Button>
                            <Button
                                variant="primary"
                                size="field"
                                fullWidth
                                disabled={!enough || belowMinimum}
                                onClick={() => {
                                    setWalking(false);
                                    setError(null);
                                    setStep('answer');
                                }}
                            >
                                {kind === 'polygon' ? 'Close the area' : 'Done'}
                            </Button>
                        </div>
                    </div>
                )}

                {step === 'answer' && chosen !== null && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            void save();
                        }}
                        className="flex flex-col gap-3"
                    >
                        <p className="text-ui font-extrabold">{chosen.label}</p>
                        {chosen.attributes.map((attribute) => (
                            <AttributeInput
                                key={attribute.key}
                                attribute={attribute}
                                value={answers[attribute.key]}
                                onChange={(value) => {
                                    setAnswer(attribute.key, value);
                                }}
                            />
                        ))}
                        <label className="flex flex-col gap-1 text-label font-semibold text-muted">
                            Notes
                            <textarea
                                value={notes}
                                onChange={(e) => {
                                    setNotes(e.target.value);
                                }}
                                rows={2}
                                className="rounded-sm border border-rule-strong px-3 py-2 text-ui font-normal text-ink"
                            />
                        </label>
                        <div>
                            <p className="text-label font-semibold text-muted">Photographs ({photos.length})</p>
                            <label className="mt-1.5 inline-flex min-h-touch cursor-pointer items-center rounded-sm border border-rule-strong px-4 text-ui font-semibold text-ink">
                                Take a photograph
                                <input
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    className="sr-only"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];

                                        if (file !== undefined) {
                                            setPhotos((current) => [...current, { file, bearing: heading.current }]);
                                        }

                                        e.target.value = '';
                                    }}
                                />
                            </label>
                        </div>
                        {error !== null && (
                            <p role="alert" className="text-label text-alert">
                                {error}
                            </p>
                        )}
                        <div className="flex gap-2">
                            <Button
                                variant="quiet"
                                size="field"
                                onClick={() => {
                                    setStep('draw');
                                }}
                            >
                                Back to the shape
                            </Button>
                            <Button type="submit" variant="primary" size="field-primary" fullWidth>
                                Save {chosen.label.toLowerCase()}
                            </Button>
                        </div>
                    </form>
                )}

                {step !== 'answer' && error !== null && (
                    <p role="alert" className="mt-2 text-label text-alert">
                        {error}
                    </p>
                )}
            </section>
        </div>
    );
}

/** One question from the class's form, as the right control. */
function AttributeInput({ attribute, value, onChange }: { attribute: FeatureAttribute; value: Answer | undefined; onChange: (value: Answer) => void }) {
    const label = (
        <span className="text-label font-semibold text-muted">
            {attribute.label}
            {attribute.required && ' *'}
            {attribute.unit !== undefined && ` (${attribute.unit})`}
        </span>
    );
    const control = 'min-h-touch rounded-sm border border-rule-strong bg-raised px-3 text-ui text-ink';

    switch (attribute.type) {
        case 'select':
            return (
                <label className="flex flex-col gap-1">
                    {label}
                    <select
                        value={typeof value === 'string' ? value : ''}
                        onChange={(e) => {
                            onChange(e.target.value);
                        }}
                        className={control}
                    >
                        <option value="">Choose</option>
                        {(attribute.options ?? []).map((option) => (
                            <option key={option} value={option}>
                                {option}
                            </option>
                        ))}
                    </select>
                </label>
            );
        case 'multiselect': {
            const chosen = Array.isArray(value) ? value : [];

            return (
                <fieldset className="flex flex-col gap-1">
                    <legend>{label}</legend>
                    <div className="flex flex-wrap gap-1.5">
                        {(attribute.options ?? []).map((option) => (
                            <button
                                key={option}
                                type="button"
                                aria-pressed={chosen.includes(option)}
                                onClick={() => {
                                    onChange(chosen.includes(option) ? chosen.filter((c) => c !== option) : [...chosen, option]);
                                }}
                                className={cx(
                                    'min-h-[40px] rounded-full border px-3 text-label font-semibold',
                                    chosen.includes(option) ? 'border-gold-dark bg-green-soft text-ink' : 'border-rule text-muted',
                                )}
                            >
                                {option}
                            </button>
                        ))}
                    </div>
                </fieldset>
            );
        }
        case 'boolean':
            return (
                <fieldset className="flex flex-col gap-1">
                    <legend>{label}</legend>
                    <div className="grid grid-cols-2 gap-2">
                        {[
                            [true, 'Yes'],
                            [false, 'No'],
                        ].map(([option, text]) => (
                            <button
                                key={String(text)}
                                type="button"
                                aria-pressed={value === option}
                                onClick={() => {
                                    onChange(option as boolean);
                                }}
                                className={cx('min-h-touch rounded-sm border text-ui font-semibold', value === option ? 'border-gold-dark bg-green-soft text-ink' : 'border-rule text-muted')}
                            >
                                {text}
                            </button>
                        ))}
                    </div>
                </fieldset>
            );
        case 'number':
            return (
                <label className="flex flex-col gap-1">
                    {label}
                    <input
                        type="number"
                        inputMode="decimal"
                        value={typeof value === 'number' ? value : ''}
                        onChange={(e) => {
                            onChange(e.target.value === '' ? '' : Number(e.target.value));
                        }}
                        className={control}
                    />
                </label>
            );
        case 'date':
            return (
                <label className="flex flex-col gap-1">
                    {label}
                    <input
                        type="date"
                        value={typeof value === 'string' ? value : ''}
                        onChange={(e) => {
                            onChange(e.target.value);
                        }}
                        className={control}
                    />
                </label>
            );
        default:
            return (
                <label className="flex flex-col gap-1">
                    {label}
                    <input
                        value={typeof value === 'string' ? value : ''}
                        onChange={(e) => {
                            onChange(e.target.value);
                        }}
                        className={control}
                    />
                </label>
            );
    }
}
