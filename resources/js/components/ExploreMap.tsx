import { router } from '@inertiajs/react';
import { Map as MapLibreMap, NavigationControl, ScaleControl } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';
import { useEffect, useRef } from 'react';

export interface ExploreMapData {
    hexes: GeoJSON.FeatureCollection;
    states: GeoJSON.FeatureCollection;
    opportunities: GeoJSON.FeatureCollection;
    bounds: [[number, number], [number, number]];
    floor: number;
}

/**
 * Verified businesses as H3 density, and opportunities as cells.
 *
 * No basemap, like the console's maps: what is drawn is this register's own
 * geometry. Light ground, because this is the guide's portal and not the
 * console's dark map room. Density steps through five teals from the soft tint
 * to the dark, never a heat blur, and a hex is only there at all when it holds
 * enough businesses that it cannot point at one.
 */
export function ExploreMap({ data }: { data: ExploreMapData }) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<MapLibreMap | null>(null);

    useEffect(() => {
        if (container.current === null || map.current !== null) {
            return;
        }

        const instance = new MapLibreMap({
            container: container.current,
            style: {
                version: 8,
                sources: {},
                layers: [{ id: 'ground', type: 'background', paint: { 'background-color': '#EEF1EC' } }],
            },
            bounds: data.bounds,
            fitBoundsOptions: { padding: 48 },
            maxPitch: 0,
            attributionControl: false,
        });

        map.current = instance;
        instance.addControl(new NavigationControl({ showCompass: false }), 'top-right');
        instance.addControl(new ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-right');

        instance.on('load', () => {
            instance.addSource('states', { type: 'geojson', data: data.states });
            instance.addSource('hexes', { type: 'geojson', data: data.hexes });
            instance.addSource('opportunities', { type: 'geojson', data: data.opportunities });

            instance.addLayer({ id: 'state-fill', type: 'fill', source: 'states', paint: { 'fill-color': '#FFFFFF' } });
            instance.addLayer({
                id: 'state-line',
                type: 'line',
                source: 'states',
                paint: { 'line-color': '#D5DBD7', 'line-width': 1.2 },
            });

            const max = Math.max(
                data.floor,
                ...data.hexes.features.map((f) => (f.properties as { count?: number } | null)?.count ?? 0),
            );

            instance.addLayer({
                id: 'hex-fill',
                type: 'fill',
                source: 'hexes',
                paint: {
                    'fill-color': [
                        'step',
                        ['/', ['get', 'count'], max],
                        '#D5EFEC',
                        0.25,
                        '#A9DDD7',
                        0.5,
                        '#5DBAB1',
                        0.75,
                        '#1E8C81',
                        0.95,
                        '#0A5E57',
                    ],
                    'fill-opacity': 0.9,
                },
            });
            instance.addLayer({
                id: 'hex-line',
                type: 'line',
                source: 'hexes',
                paint: { 'line-color': '#FFFFFF', 'line-width': 1 },
            });

            instance.addLayer({
                id: 'opportunity',
                type: 'circle',
                source: 'opportunities',
                paint: {
                    'circle-radius': 9,
                    'circle-color': '#0E7C72',
                    'circle-stroke-color': '#FFFFFF',
                    'circle-stroke-width': 3,
                },
            });

            instance.on('click', 'opportunity', (event) => {
                const id = (event.features?.[0]?.properties as { id?: number } | undefined)?.id;

                if (typeof id === 'number') {
                    router.visit(`/invest/opportunities/${String(id)}`);
                }
            });
            instance.on('mouseenter', 'opportunity', () => {
                instance.getCanvas().style.cursor = 'pointer';
            });
            instance.on('mouseleave', 'opportunity', () => {
                instance.getCanvas().style.cursor = '';
            });
        });

        return () => {
            instance.remove();
            map.current = null;
        };
    }, [data]);

    return (
        <div className="relative h-[520px] overflow-hidden rounded-card border border-rule">
            <div ref={container} className="h-full w-full" aria-label="Map of verified businesses as hexagons" role="img" />
            <div className="pointer-events-none absolute bottom-4 left-4 rounded-sm bg-raised/95 px-4 py-3 shadow-float">
                <p className="text-table font-bold text-ink">Verified businesses per cell</p>
                <div className="mt-2 flex gap-1">
                    {['#D5EFEC', '#A9DDD7', '#5DBAB1', '#1E8C81', '#0A5E57'].map((c) => (
                        <span key={c} className="h-2.5 w-7 rounded-full" style={{ background: c }} />
                    ))}
                </div>
                <p className="mt-2 flex items-center gap-2 text-[0.75rem] text-muted">
                    <span className="size-3 rounded-full border-2 border-white bg-gold shadow" /> Opportunity
                    <span className="ml-2">Cells under {data.floor} are not shown</span>
                </p>
            </div>
        </div>
    );
}
