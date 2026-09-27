import { useEffect, useRef, useState } from 'react';
import {
    Map as MapLibreMap,
    NavigationControl,
    ScaleControl,
    type GeoJSONSource,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';

/*
 * The Live operations map, moved here unchanged so Team today can show the
 * same map the Live page does. Nothing about how it draws, what it draws or
 * how often it asks again differs from the Live page; both call this hook.
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

export interface Live {
    officers: LiveOfficer[];
    day: Day;
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

export function lastSeenLabel(officer: LiveOfficer): string {
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


export function useLiveMap({ live, bounds, filters }: { live: Live; bounds: [number, number, number, number]; filters: { area: number | null } }) {
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
                layers: [{ id: 'ground', type: 'background', paint: { 'background-color': '#0F1A17' } }],
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
                    'line-color': ['case', ['get', 'active'], '#4DB8B0', '#5F6B66'],
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
                    'circle-color': ['case', ['get', 'active'], '#4DB8B0', '#5F6B66'],
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
                        '#F08A66',
                        ['get', 'active'],
                        '#4DB8B0',
                        '#5F6B66',
                    ],
                    'circle-stroke-color': '#0F1A17',
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

    return { container, map, data, refreshedAt, selected, setSelected };
}
