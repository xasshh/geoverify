import { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    Map as MapLibreMap,
    NavigationControl,
    ScaleControl,
    type GeoJSONSource,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';
import { AppBar } from '@/components/AppBar';
import { PresenceMark } from '@/components/PresenceMark';
import { cx } from '@/lib/cx';

interface LiveOfficer {
    officerId: number;
    officer: string;
    staffRef: string | null;
    sessionId: number;
    startedAt: string;
    endedAt: string | null;
    lastSeenAt: string | null;
    active: boolean;
    longitude: number | null;
    latitude: number | null;
    accuracyM: number | null;
    isMock: boolean;
    distanceM: number;
    integrityVerdict: string;
    h3: string | null;
    captures: number;
    awaiting: number;
    meanConfidence: number | null;
    trace: Array<[number, number]>;
}

interface Day {
    captures: number;
    officers: number;
    meanConfidence: number | null;
    contested: number;
}

interface Live {
    officers: LiveOfficer[];
    day: Day;
}

interface LiveProps {
    live: Live;
    bounds: [number, number, number, number];
    areas: Array<{ id: number; name: string }>;
    filters: { area: number | null };
}

/** How often the console asks again. Slow enough to be free, quick enough to matter. */
const POLL_MS = 20_000;

function minutesSince(iso: string | null): number | null {
    if (iso === null) {
        return null;
    }

    // The server sends ISO 8601 with an offset, so this is unambiguous wherever
    // the console is open. A naive timestamp would be read as browser local and
    // put "last seen" out by the server's offset.
    const then = new Date(iso).getTime();

    return Number.isNaN(then) ? null : Math.floor((Date.now() - then) / 60_000);
}

function lastSeenLabel(officer: LiveOfficer): string {
    const minutes = minutesSince(officer.lastSeenAt);

    if (minutes === null) {
        return 'no fix yet';
    }

    if (minutes < 1) {
        return 'just now';
    }

    return minutes < 60 ? `${String(minutes)} min ago` : `${String(Math.floor(minutes / 60))} h ago`;
}

function officersToGeoJSON(officers: LiveOfficer[]): GeoJSON.FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: officers
            .filter((o) => o.longitude !== null && o.latitude !== null)
            .map((o) => ({
                type: 'Feature',
                id: o.officerId,
                geometry: {
                    type: 'Point',
                    coordinates: [o.longitude ?? 0, o.latitude ?? 0],
                },
                properties: {
                    officer: o.officer,
                    active: o.active,
                    mock: o.isMock,
                },
            })),
    };
}

function tracesToGeoJSON(officers: LiveOfficer[]): GeoJSON.FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: officers
            .filter((o) => o.trace.length > 1)
            .map((o) => ({
                type: 'Feature',
                id: o.officerId,
                geometry: { type: 'LineString', coordinates: o.trace },
                properties: { officer: o.officer, active: o.active },
            })),
    };
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: string }) {
    return (
        <div className="flex flex-col gap-0.5 border-l-2 border-rule-strong pl-3">
            <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                {label}
            </span>
            <span className={cx('numeric-mono text-display-s', tone ?? 'text-ink')}>{value}</span>
        </div>
    );
}

/**
 * Live operations.
 *
 * A supervisor with eight officers out cannot ring each of them, and what they
 * need is not "is everyone busy" but "is anyone stuck, lost, or somewhere they
 * should not be". So the officers who have gone quiet sort to the top, and the
 * day's contested captures are on the page rather than a click away.
 *
 * The traces are drawn on the map at full length and repeated as Presence Marks
 * in the list, because the mark carries density where the map carries route: a
 * fabricated day is a straight line in the column before anyone reads a number.
 */
export default function Live({ live, bounds, areas, filters }: LiveProps) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<MapLibreMap | null>(null);
    const [ready, setReady] = useState(false);
    const [data, setData] = useState<Live>(live);
    const [refreshedAt, setRefreshedAt] = useState<Date>(new Date());
    const [selected, setSelected] = useState<number | null>(null);

    // No effect syncs the prop into state, and none is needed: changing the
    // mandate navigates with preserveState false, so the page remounts and the
    // initial state is the new reading. An in flight poll from the old mandate
    // is dropped by its own cleanup rather than landing on the new one.

    useEffect(() => {
        if (container.current === null || map.current !== null) {
            return;
        }

        const instance = new MapLibreMap({
            container: container.current,
            // No basemap, like the coverage view: what is drawn is this system's
            // own geometry, which is the thing worth looking at.
            style: {
                version: 8,
                sources: {},
                layers: [{ id: 'ground', type: 'background', paint: { 'background-color': '#0E1E2E' } }],
            },
            bounds,
            fitBoundsOptions: { padding: 56 },
            maxPitch: 0,
            attributionControl: false,
        });

        map.current = instance;
        instance.addControl(new NavigationControl({ showCompass: false }), 'top-right');
        instance.addControl(new ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-left');

        instance.on('load', () => {
            instance.addSource('traces', { type: 'geojson', data: tracesToGeoJSON(live.officers) });
            instance.addSource('officers', {
                type: 'geojson',
                data: officersToGeoJSON(live.officers),
            });

            instance.addLayer({
                id: 'trace-line',
                type: 'line',
                source: 'traces',
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: {
                    'line-color': ['case', ['get', 'active'], '#D0AE63', '#5A6B7A'],
                    'line-width': 1.8,
                    'line-opacity': 0.85,
                },
            });

            instance.addLayer({
                id: 'officer-halo',
                type: 'circle',
                source: 'officers',
                paint: {
                    'circle-radius': 12,
                    'circle-color': ['case', ['get', 'active'], '#D0AE63', '#5A6B7A'],
                    'circle-opacity': 0.18,
                },
            });

            instance.addLayer({
                id: 'officer-dot',
                type: 'circle',
                source: 'officers',
                paint: {
                    'circle-radius': 5,
                    'circle-color': [
                        'case',
                        ['get', 'mock'],
                        '#C2564B',
                        ['get', 'active'],
                        '#D0AE63',
                        '#5A6B7A',
                    ],
                    'circle-stroke-color': '#0E1E2E',
                    'circle-stroke-width': 1.5,
                },
            });

            instance.on('click', 'officer-dot', (event) => {
                const id = event.features?.[0]?.id;
                setSelected(typeof id === 'number' ? id : null);
            });

            setReady(true);
        });

        return () => {
            instance.remove();
            map.current = null;
            setReady(false);
        };
    }, [bounds, live.officers]);

    /** New readings go straight into the sources: no restyle, no flicker. */
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        const traces = instance.getSource<GeoJSONSource>('traces');
        const officers = instance.getSource<GeoJSONSource>('officers');

        if (traces !== undefined) {
            void traces.setData(tracesToGeoJSON(data.officers));
        }

        if (officers !== undefined) {
            void officers.setData(officersToGeoJSON(data.officers));
        }
    }, [ready, data]);

    useEffect(() => {
        let cancelled = false;

        const tick = () => {
            const query = filters.area === null ? '' : `?area=${String(filters.area)}`;

            fetch(`/console/live/feed.json${query}`, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? (response.json() as Promise<Live>) : null))
                .then((next) => {
                    if (!cancelled && next !== null) {
                        setData(next);
                        setRefreshedAt(new Date());
                    }
                })
                .catch(() => {
                    // A console that has lost the server should keep showing the
                    // last reading it trusted rather than emptying the map.
                });
        };

        const timer = window.setInterval(tick, POLL_MS);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [filters.area]);

    const quiet = data.officers.filter((o) => !o.active).length;

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title="Live operations" />
            <AppBar
                variant="console"
                links={[
                    { label: 'Coverage', href: '/console/coverage', current: false },
                    { label: 'Review', href: '/console/review', current: false },
                    { label: 'Live', href: '/console/live', current: true },
                ]}
            />

            <div className="mx-auto max-w-[1400px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            Supervision
                        </p>
                        <h1 className="font-display text-display-m text-ink">Live operations</h1>
                    </div>
                    <div className="flex items-center gap-4">
                        <select
                            value={filters.area ?? ''}
                            onChange={(event) => {
                                router.get(
                                    '/console/live',
                                    event.target.value === '' ? {} : { area: event.target.value },
                                    { preserveState: false, replace: true },
                                );
                            }}
                            className="rounded-sm border border-rule-strong bg-surface px-3 py-1.5 text-ui text-ink"
                        >
                            <option value="">Every mandate</option>
                            {areas.map((area) => (
                                <option key={area.id} value={area.id}>
                                    {area.name}
                                </option>
                            ))}
                        </select>
                        <p className="numeric-mono text-mono text-faint">
                            checked {refreshedAt.toLocaleTimeString()}
                        </p>
                    </div>
                </header>

                <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Captured today" value={String(data.day.captures)} />
                    <Stat label="Officers capturing" value={String(data.day.officers)} />
                    <Stat
                        label="Mean confidence"
                        value={data.day.meanConfidence === null ? 'none' : String(data.day.meanConfidence)}
                    />
                    <Stat
                        label="Contested today"
                        value={String(data.day.contested)}
                        tone={data.day.contested > 0 ? 'text-alert' : 'text-ink'}
                    />
                </div>

                <div className="mt-6 grid gap-5 lg:grid-cols-[1fr_380px]">
                    <div className="relative h-[560px] overflow-hidden rounded-sm border border-rule-strong">
                        <div ref={container} className="h-full w-full" data-testid="live-map" />
                        {data.officers.length === 0 && (
                            <p className="pointer-events-none absolute inset-0 flex items-center justify-center text-ui text-faint">
                                Nobody has opened a session in the last eighteen hours.
                            </p>
                        )}
                    </div>

                    <aside className="flex max-h-[560px] flex-col gap-2 overflow-y-auto">
                        {quiet > 0 && (
                            <p className="rounded-sm border-l-2 border-amber bg-raised px-3 py-2 text-ui text-muted">
                                {quiet === 1
                                    ? '1 officer has gone quiet.'
                                    : `${String(quiet)} officers have gone quiet.`}
                            </p>
                        )}

                        {data.officers.map((officer) => (
                            <button
                                key={officer.officerId}
                                type="button"
                                onClick={() => {
                                    setSelected(officer.officerId);

                                    if (officer.longitude !== null && officer.latitude !== null) {
                                        map.current?.easeTo({
                                            center: [officer.longitude, officer.latitude],
                                            zoom: 15,
                                            duration: 600,
                                        });
                                    }
                                }}
                                className={cx(
                                    'flex gap-3 rounded-sm border p-3 text-left',
                                    selected === officer.officerId
                                        ? 'border-gold bg-raised'
                                        : 'border-rule hover:border-rule-strong',
                                )}
                            >
                                <PresenceMark
                                    points={officer.trace}
                                    size={40}
                                    tone={officer.isMock ? 'alert' : officer.active ? 'gold' : 'green'}
                                    showCapturePoint={false}
                                    label={`${officer.officer}'s trace today, ${String(officer.trace.length)} points`}
                                />

                                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span className="flex items-baseline justify-between gap-2">
                                        <span className="truncate text-ui font-semibold text-ink">
                                            {officer.officer}
                                        </span>
                                        <span
                                            className={cx(
                                                'numeric-mono shrink-0 text-label',
                                                officer.active ? 'text-green' : 'text-amber',
                                            )}
                                        >
                                            {lastSeenLabel(officer)}
                                        </span>
                                    </span>

                                    <span className="numeric-mono text-label text-faint">
                                        {officer.captures} captured · {(officer.distanceM / 1000).toFixed(1)} km
                                        {officer.meanConfidence !== null &&
                                            ` · conf ${String(officer.meanConfidence)}`}
                                    </span>

                                    {officer.h3 !== null && (
                                        <span className="numeric-mono text-label text-faint">
                                            {officer.h3}
                                        </span>
                                    )}

                                    {officer.isMock && (
                                        <span className="text-label text-alert">
                                            A mock location provider is running on this device.
                                        </span>
                                    )}
                                </span>
                            </button>
                        ))}
                    </aside>
                </div>
            </div>
        </div>
    );
}
