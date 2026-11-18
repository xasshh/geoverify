import { useCallback, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import {
    LngLatBounds,
    Popup,
    type ExpressionSpecification,
    type GeoJSONSource,
    type Map as MapLibreMap,
} from 'maplibre-gl';
import { BASEMAP_FONT } from '@/lib/basemap';
import { createGeoMap, type Imagery } from '@/lib/geomap';
import { addFootprintImage, footprintLayer } from '@/lib/footprints';

/*
 * The Live operations map, shared by the Live page and Team today: both call
 * this hook, so they draw the same thing from the same feed.
 *
 * Officers group into numbered clusters when zoomed out and separate as the
 * map zooms in. Today's captures are dots coloured by confidence, with an
 * activity heatmap behind them on request. Any officer's day can be replayed:
 * the positions arrive with their times on request, and a slider (or play)
 * walks the route with the captures appearing as they were made.
 */

export interface LiveOfficer {
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

export interface Day {
    captures: number;
    officers: number;
    meanConfidence: number | null;
    contested: number;
}

/** A capture: longitude, latitude, confidence (null until scored). */
export type CapturePoint = [number, number, number | null];

export interface Live {
    officers: LiveOfficer[];
    day: Day;
    captures: CapturePoint[];
}

/** A replayed session: positions with epoch seconds, and captures with theirs. */
export interface Replay {
    sessionId: number;
    officer: string;
    points: Array<[number, number, number, number | null, boolean]>;
    captures: Array<[number, number, number, number | null]>;
}

/**
 * How often the console asks again while it is on screen. Positions reach the
 * server only when a handset syncs, so asking much faster than this shows
 * nothing new; a hidden tab does not ask at all, and asks at once on return.
 */
const POLL_MS = 15_000;

/** Confidence as a colour: accepted territory, worth a look, contested, unscored. */
const CONFIDENCE_TONE: ExpressionSpecification = [
    'case',
    ['==', ['get', 'confidence'], null],
    '#8A948F',
    ['>=', ['get', 'confidence'], 70],
    '#2E8F5E',
    ['>=', ['get', 'confidence'], 45],
    '#D99A2B',
    '#D2553A',
];

const EMPTY: GeoJSON.FeatureCollection = { type: 'FeatureCollection', features: [] };

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

export function lastSeenLabel(officer: LiveOfficer): string {
    const minutes = minutesSince(officer.lastSeenAt);

    if (minutes === null) {
        return 'no position yet';
    }

    if (minutes < 1) {
        return 'just now';
    }

    return minutes < 60 ? `${String(minutes)} min ago` : `${String(Math.floor(minutes / 60))} h ago`;
}

/** "Bello Musa" to "BM", for the marker. Letters only, at most two. */
function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.replace(/[^A-Za-z]/g, '').charAt(0))
        .join('')
        .toUpperCase();
}

/** Nigeria, for a console with no mandate drawn yet. */
const NIGERIA: [number, number, number, number] = [2.67, 4.27, 14.68, 13.89];

/**
 * Where the map opens: around the officers who have reported a position, so a
 * supervisor sees their people rather than a whole mandate with one dot in it;
 * the mandate when nobody has; Nigeria when there is no mandate either.
 */
function openingBounds(officers: LiveOfficer[], mandate: [number, number, number, number]): LngLatBounds {
    const placed = officers.filter((o) => o.longitude !== null && o.latitude !== null);

    if (placed.length > 0) {
        const box = new LngLatBounds();

        for (const o of placed) {
            box.extend([o.longitude ?? 0, o.latitude ?? 0]);
        }

        return box;
    }

    const empty = mandate.every((v) => v === 0);
    const [w, s, e, n] = empty ? NIGERIA : mandate;

    return new LngLatBounds([w, s], [e, n]);
}

/** The officer's card on the map, built as nodes so a name is never read as markup. */
function officerCard(officer: LiveOfficer, onReplay: (sessionId: number) => void): HTMLElement {
    const card = document.createElement('div');
    card.className = 'flex min-w-[200px] flex-col gap-1 p-1 text-ink';

    const line = (text: string, className: string) => {
        const p = document.createElement('p');
        p.className = className;
        p.textContent = text;
        card.appendChild(p);
    };

    line(officer.officer, 'text-ui font-bold');
    line(
        officer.active ? `Active, seen ${lastSeenLabel(officer)}` : `No signal, last seen ${lastSeenLabel(officer)}`,
        officer.active ? 'text-table text-green' : 'text-table text-amber-ink',
    );
    line(
        `${String(officer.captures)} captured today, ${(officer.distanceM / 1000).toFixed(1)} km walked`,
        'text-table text-muted',
    );

    if (officer.meanConfidence !== null) {
        line(`Mean confidence ${String(officer.meanConfidence)}`, 'text-table text-muted');
    }

    if (officer.accuracyM !== null) {
        line(`Position accurate to ${String(Math.round(officer.accuracyM))} m`, 'text-table text-muted');
    }

    if (officer.isMock) {
        line('A mock location provider is running on this device.', 'text-table font-semibold text-alert');
    }

    const replay = document.createElement('button');
    replay.type = 'button';
    replay.className = 'mt-1 self-start rounded-sm border border-rule-strong px-2.5 py-1 text-table font-bold text-ink hover:bg-sunken';
    replay.textContent = 'Replay this day';
    replay.addEventListener('click', () => {
        onReplay(officer.sessionId);
    });
    card.appendChild(replay);

    return card;
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
                    officerId: o.officerId,
                    officer: o.officer,
                    initials: initials(o.officer),
                    active: o.active,
                    mock: o.isMock,
                },
            })),
    };
}

function capturesToGeoJSON(captures: CapturePoint[] | undefined): GeoJSON.FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: (captures ?? []).map(([lon, lat, confidence]) => ({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [lon, lat] },
            properties: { confidence },
        })),
    };
}

/** The first and last moments of a replay, in epoch seconds. */
export function replaySpan(replay: Replay): [number, number] {
    const first = replay.points[0];
    const last = replay.points[replay.points.length - 1];

    return [first?.[2] ?? 0, last?.[2] ?? 0];
}

/** Where the replay stands at time t: the route walked so far, the head, and the captures made. */
function replayToGeoJSON(replay: Replay, t: number): GeoJSON.FeatureCollection {
    const points = replay.points;

    // The last position at or before t, by halving: a day is a thousand points.
    let last = 0;
    let low = 0;
    let high = points.length - 1;

    while (low <= high) {
        const mid = (low + high) >> 1;

        if ((points[mid]?.[2] ?? Infinity) <= t) {
            last = mid;
            low = mid + 1;
        } else {
            high = mid - 1;
        }
    }

    const here = points[last];

    if (here === undefined) {
        return EMPTY;
    }

    const walked = points.slice(0, last + 1).map(([lon, lat]) => [lon, lat]);
    const next = points[last + 1];
    let head: [number, number] = [here[0], here[1]];

    // Between two positions the head moves along the straight line, so the
    // replay glides rather than jumping from fix to fix.
    if (next !== undefined && next[2] > here[2]) {
        const share = Math.min(1, Math.max(0, (t - here[2]) / (next[2] - here[2])));
        head = [here[0] + (next[0] - here[0]) * share, here[1] + (next[1] - here[1]) * share];
        walked.push(head);
    }

    return {
        type: 'FeatureCollection',
        features: [
            {
                type: 'Feature',
                geometry: { type: 'LineString', coordinates: points.map(([lon, lat]) => [lon, lat]) },
                properties: { part: 'route' },
            },
            ...(walked.length > 1
                ? [
                      {
                          type: 'Feature' as const,
                          geometry: { type: 'LineString' as const, coordinates: walked },
                          properties: { part: 'walked' },
                      },
                  ]
                : []),
            ...replay.captures
                .filter((c) => c[2] <= t)
                .map(([lon, lat, , confidence]) => ({
                    type: 'Feature' as const,
                    geometry: { type: 'Point' as const, coordinates: [lon, lat] },
                    properties: { part: 'capture', confidence },
                })),
            {
                type: 'Feature',
                geometry: { type: 'Point', coordinates: head },
                properties: { part: 'head', mock: here[4] },
            },
        ],
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


/** The layer groups the map panel offers on a live map. */
export const LIVE_LAYERS = [
    {
        label: 'Officers',
        ids: ['officer-cluster', 'officer-cluster-count', 'officer-halo', 'officer-dot', 'officer-initials', 'officer-name'],
    },
    { label: 'Walking trails', ids: ['trace-steps'] },
    { label: 'Captures today', ids: ['captures-dot'] },
    { label: 'Activity heatmap', ids: ['captures-heat'], visible: false },
];

/** Replay speeds: minutes of the day per second of watching. */
export const REPLAY_SPEEDS = [1, 5, 15] as const;

export interface ReplayState {
    replay: Replay;
    /** Where the replay stands, in epoch seconds. */
    t: number;
    playing: boolean;
    speed: (typeof REPLAY_SPEEDS)[number];
}

const UNCLUSTERED: ExpressionSpecification = ['!', ['has', 'point_count']];

export function useLiveMap({
    live,
    bounds,
    filters,
    imagery = [],
}: {
    live: Live;
    bounds: [number, number, number, number];
    filters: { area: number | null };
    imagery?: Imagery[];
}) {
    const { basemap } = usePage().props;
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<MapLibreMap | null>(null);
    const popup = useRef<Popup | null>(null);
    const [ready, setReady] = useState(false);
    const [data, setData] = useState<Live>(live);
    const [refreshedAt, setRefreshedAt] = useState<Date>(new Date());
    const [selected, setSelected] = useState<number | null>(null);
    const [loaded, setLoaded] = useState<MapLibreMap | null>(null);
    const [replay, setReplay] = useState<ReplayState | null>(null);
    const [replayError, setReplayError] = useState<string | null>(null);

    // No effect syncs the prop into state, and none is needed: changing the
    // mandate navigates with preserveState false, so the page remounts and the
    // initial state is the new reading. An in flight poll from the old mandate
    // is dropped by its own cleanup rather than landing on the new one.

    /** Fetches a session's day and starts its replay at the beginning. */
    const startReplay = useCallback((sessionId: number) => {
        setReplayError(null);

        fetch(`/console/live/sessions/${String(sessionId)}/replay.json`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? (response.json() as Promise<Replay>) : null))
            .then((next) => {
                if (next === null || next.points.length === 0) {
                    setReplayError('There are no recorded positions to replay for this session.');

                    return;
                }

                setSelected(null);
                setReplay({ replay: next, t: replaySpan(next)[0], playing: true, speed: 5 });

                const box = new LngLatBounds();

                for (const [lon, lat] of next.points) {
                    box.extend([lon, lat]);
                }

                map.current?.fitBounds(box, { padding: 80, maxZoom: 17, duration: 600 });
            })
            .catch(() => {
                setReplayError('The replay could not be loaded. Try again in a moment.');
            });
    }, []);

    // The card is built outside React, so it reaches the latest callback through a ref.
    const replayRef = useRef(startReplay);

    useEffect(() => {
        replayRef.current = startReplay;
    }, [startReplay]);

    useEffect(() => {
        if (container.current === null || map.current !== null) {
            return;
        }

        // The street map under the officers, so a supervisor can say which
        // street somebody is on; plain ground when the server has none.
        const instance = createGeoMap({
            container: container.current,
            basemap,
            ground: '#0F1A17',
            bounds: openingBounds(live.officers, bounds),
            padding: 72,
            maxFitZoom: 15,
            imagery,
        });

        map.current = instance;

        instance.on('load', () => {
            // Captures first, so the people walking among them draw on top.
            instance.addSource('captures', { type: 'geojson', data: capturesToGeoJSON(live.captures) });
            instance.addLayer({
                id: 'captures-heat',
                type: 'heatmap',
                source: 'captures',
                maxzoom: 17,
                layout: { visibility: 'none' },
                paint: {
                    'heatmap-radius': ['interpolate', ['linear'], ['zoom'], 9, 10, 15, 32],
                    'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 9, 0.6, 15, 1.4],
                    'heatmap-opacity': 0.75,
                    'heatmap-color': [
                        'interpolate',
                        ['linear'],
                        ['heatmap-density'],
                        0,
                        'rgba(46,143,136,0)',
                        0.3,
                        '#7CC6BF',
                        0.6,
                        '#E8C15A',
                        1,
                        '#D2553A',
                    ],
                },
            });
            instance.addLayer({
                id: 'captures-dot',
                type: 'circle',
                source: 'captures',
                minzoom: 11,
                paint: {
                    'circle-radius': ['interpolate', ['linear'], ['zoom'], 11, 2.5, 16, 6],
                    'circle-color': CONFIDENCE_TONE,
                    'circle-stroke-color': '#FFFFFF',
                    'circle-stroke-width': 1,
                },
            });

            instance.addSource('traces', { type: 'geojson', data: tracesToGeoJSON(live.officers) });

            // Clustered: with forty officers in one ward the dots become one
            // number, and zooming in pulls them apart again.
            instance.addSource('officers', {
                type: 'geojson',
                data: officersToGeoJSON(live.officers),
                cluster: true,
                clusterRadius: 48,
                clusterMaxZoom: 13,
            });

            // Each officer's walk as footprints: teal while they are active,
            // grey once they have gone quiet.
            addFootprintImage(instance, 'footprints-active', '#2E8F88');
            addFootprintImage(instance, 'footprints-idle', '#5F6B66');
            instance.addLayer(footprintLayer('trace-steps', 'traces', ['case', ['get', 'active'], 'footprints-active', 'footprints-idle'], 0.95));

            const tone: ExpressionSpecification = [
                'case',
                ['get', 'mock'],
                '#D2553A',
                ['get', 'active'],
                '#2E8F88',
                '#6B7570',
            ];

            instance.addLayer({
                id: 'officer-cluster',
                type: 'circle',
                source: 'officers',
                filter: ['has', 'point_count'],
                paint: {
                    'circle-radius': ['step', ['get', 'point_count'], 16, 10, 20, 50, 26],
                    'circle-color': '#16211E',
                    'circle-stroke-color': '#2E8F88',
                    'circle-stroke-width': 3,
                },
            });

            instance.addLayer({
                id: 'officer-halo',
                type: 'circle',
                source: 'officers',
                filter: UNCLUSTERED,
                paint: {
                    'circle-radius': 22,
                    'circle-color': tone,
                    'circle-opacity': 0.2,
                },
            });

            instance.addLayer({
                id: 'officer-dot',
                type: 'circle',
                source: 'officers',
                filter: UNCLUSTERED,
                paint: {
                    'circle-radius': basemap === null ? 6 : 13,
                    'circle-color': tone,
                    'circle-stroke-color': '#FFFFFF',
                    'circle-stroke-width': 2,
                },
            });

            // Text needs the basemap's fonts, so without them the dot stands alone.
            if (basemap !== null) {
                instance.addLayer({
                    id: 'officer-cluster-count',
                    type: 'symbol',
                    source: 'officers',
                    filter: ['has', 'point_count'],
                    layout: {
                        'text-field': ['get', 'point_count_abbreviated'],
                        'text-font': BASEMAP_FONT,
                        'text-size': 13,
                        'text-allow-overlap': true,
                    },
                    paint: { 'text-color': '#FFFFFF' },
                });

                instance.addLayer({
                    id: 'officer-initials',
                    type: 'symbol',
                    source: 'officers',
                    filter: UNCLUSTERED,
                    layout: {
                        'text-field': ['get', 'initials'],
                        'text-font': BASEMAP_FONT,
                        'text-size': 11,
                        'text-allow-overlap': true,
                        'text-ignore-placement': true,
                    },
                    paint: { 'text-color': '#FFFFFF' },
                });

                instance.addLayer({
                    id: 'officer-name',
                    type: 'symbol',
                    source: 'officers',
                    filter: UNCLUSTERED,
                    minzoom: 11,
                    layout: {
                        'text-field': ['get', 'officer'],
                        'text-font': BASEMAP_FONT,
                        'text-size': 13,
                        'text-anchor': 'left',
                        'text-offset': [1.6, 0],
                        'text-optional': true,
                    },
                    paint: {
                        'text-color': '#16211E',
                        'text-halo-color': '#FFFFFF',
                        'text-halo-width': 1.6,
                    },
                });
            }

            // The replay draws over everything: the route ahead faint, the
            // route walked bold, captures as they happen, the officer as a ring.
            instance.addSource('replay', { type: 'geojson', data: EMPTY });
            instance.addLayer({
                id: 'replay-route',
                type: 'line',
                source: 'replay',
                filter: ['==', ['get', 'part'], 'route'],
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-color': '#16211E', 'line-width': 2, 'line-opacity': 0.25, 'line-dasharray': [2, 2] },
            });
            instance.addLayer({
                id: 'replay-walked',
                type: 'line',
                source: 'replay',
                filter: ['==', ['get', 'part'], 'walked'],
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-color': '#2E8F88', 'line-width': 4 },
            });
            instance.addLayer({
                id: 'replay-capture',
                type: 'circle',
                source: 'replay',
                filter: ['==', ['get', 'part'], 'capture'],
                paint: {
                    'circle-radius': 6,
                    'circle-color': CONFIDENCE_TONE,
                    'circle-stroke-color': '#FFFFFF',
                    'circle-stroke-width': 2,
                },
            });
            instance.addLayer({
                id: 'replay-head',
                type: 'circle',
                source: 'replay',
                filter: ['==', ['get', 'part'], 'head'],
                paint: {
                    'circle-radius': 9,
                    'circle-color': ['case', ['get', 'mock'], '#D2553A', '#FFFFFF'],
                    'circle-stroke-color': ['case', ['get', 'mock'], '#FFFFFF', '#2E8F88'],
                    'circle-stroke-width': 4,
                },
            });

            instance.on('click', 'officer-dot', (event) => {
                const id: unknown = event.features?.[0]?.properties.officerId;
                setSelected(typeof id === 'number' ? id : null);
            });

            // A cluster opens to the zoom at which it splits apart.
            instance.on('click', 'officer-cluster', (event) => {
                const feature = event.features?.[0];
                const clusterId: unknown = feature?.properties.cluster_id;
                const source = instance.getSource<GeoJSONSource>('officers');

                if (feature === undefined || typeof clusterId !== 'number' || source === undefined || feature.geometry.type !== 'Point') {
                    return;
                }

                const centre = feature.geometry.coordinates as [number, number];

                void source.getClusterExpansionZoom(clusterId).then((zoom) => {
                    instance.easeTo({ center: centre, zoom, duration: 500 });
                });
            });

            for (const layer of ['officer-dot', 'officer-cluster']) {
                instance.on('mouseenter', layer, () => {
                    instance.getCanvas().style.cursor = 'pointer';
                });
                instance.on('mouseleave', layer, () => {
                    instance.getCanvas().style.cursor = '';
                });
            }

            setLoaded(instance);
            setReady(true);
        });

        return () => {
            const open = popup.current;
            popup.current = null;
            open?.remove();
            instance.remove();
            map.current = null;
            setLoaded(null);
            setReady(false);
        };
    }, [basemap, bounds, live.officers, live.captures, imagery]);

    /**
     * The selected officer's card, from a click on the map or on the list,
     * kept current as new readings arrive. Closing it clears the selection.
     */
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        const officer = data.officers.find((o) => o.officerId === selected);

        if (officer === undefined || officer.longitude === null || officer.latitude === null) {
            const open = popup.current;
            popup.current = null;
            open?.remove();

            return;
        }

        const at: [number, number] = [officer.longitude, officer.latitude];
        const content = officerCard(officer, (sessionId) => {
            replayRef.current(sessionId);
        });

        if (popup.current === null) {
            const card = new Popup({ offset: 18, closeButton: true, maxWidth: '280px' });

            // Only a close the supervisor made clears the selection; the code
            // removing a card it no longer wants has already let go of it.
            card.on('close', () => {
                if (popup.current === card) {
                    popup.current = null;
                    setSelected(null);
                }
            });

            popup.current = card;
            card.setLngLat(at).setDOMContent(content).addTo(instance);

            return;
        }

        popup.current.setLngLat(at).setDOMContent(content);
    }, [ready, data, selected]);

    /** New readings go straight into the sources: no restyle, no flicker. */
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        void instance.getSource<GeoJSONSource>('traces')?.setData(tracesToGeoJSON(data.officers));
        void instance.getSource<GeoJSONSource>('officers')?.setData(officersToGeoJSON(data.officers));
        void instance.getSource<GeoJSONSource>('captures')?.setData(capturesToGeoJSON(data.captures));
    }, [ready, data]);

    /** The replay drawn where it stands. */
    useEffect(() => {
        const source = ready ? map.current?.getSource<GeoJSONSource>('replay') : undefined;

        if (source === undefined) {
            return;
        }

        void source.setData(replay === null ? EMPTY : replayToGeoJSON(replay.replay, replay.t));
    }, [ready, replay]);

    /** Playing: the clock advances with the frames, and stops at the day's end. */
    const playing = replay?.playing ?? false;

    useEffect(() => {
        if (!playing) {
            return;
        }

        let frame = 0;
        let before = performance.now();

        const step = (now: number) => {
            const elapsed = (now - before) / 1000;
            before = now;

            setReplay((was) => {
                if (was === null || !was.playing) {
                    return was;
                }

                const end = replaySpan(was.replay)[1];
                const t = Math.min(end, was.t + elapsed * was.speed * 60);

                return { ...was, t, playing: t < end };
            });

            frame = requestAnimationFrame(step);
        };

        frame = requestAnimationFrame(step);

        return () => {
            cancelAnimationFrame(frame);
        };
    }, [playing]);

    useEffect(() => {
        let cancelled = false;

        const tick = () => {
            if (document.visibilityState === 'hidden') {
                return;
            }

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

        // Back on screen after a while away: read now, not at the next tick.
        const returned = () => {
            if (document.visibilityState === 'visible') {
                tick();
            }
        };

        document.addEventListener('visibilitychange', returned);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', returned);
        };
    }, [filters.area]);

    const controls = {
        state: replay,
        error: replayError,
        start: startReplay,
        stop: () => {
            setReplay(null);
            setReplayError(null);
        },
        toggle: () => {
            setReplay((was) => {
                if (was === null) {
                    return was;
                }

                const [start, end] = replaySpan(was.replay);

                // Play at the end starts again from the beginning.
                return was.playing ? { ...was, playing: false } : { ...was, playing: true, t: was.t >= end ? start : was.t };
            });
        },
        seek: (t: number) => {
            setReplay((was) => (was === null ? was : { ...was, t, playing: false }));
        },
        speed: (speed: ReplayState['speed']) => {
            setReplay((was) => (was === null ? was : { ...was, speed }));
        },
    };

    return { container, map, loaded, data, refreshedAt, selected, setSelected, replay: controls };
}
