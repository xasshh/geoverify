import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { type GeoJSONSource, type Map as MapLibreMap, type MapLayerMouseEvent } from 'maplibre-gl';
import { MapPanel } from '@/components/MapPanel';
import { BASEMAP_FONT } from '@/lib/basemap';
import { createGeoMap } from '@/lib/geomap';
import { UNIT_COLOUR } from '@/lib/pollingUnits';

const UNIT_LAYERS = [
    { label: 'Polling units', ids: ['unit'] },
    { label: 'LGA outlines', ids: ['lga-fill', 'lga-line', 'lga-label'] },
];

/**
 * Every polling unit with the LGAs outlined, on the street basemap this
 * application serves (or plain ground without it): no tiles from anybody
 * else's server. A dot is where an officer confirmed the unit, or where the
 * list puts it while nobody has.
 */
export function PollingUnitMap({ url, bounds, onSelect }: { url: string; bounds: [number, number, number, number] | null; onSelect: (id: number) => void }) {
    const { basemap } = usePage().props;
    const container = useRef<HTMLDivElement>(null);
    const select = useRef(onSelect);
    const [loaded, setLoaded] = useState<MapLibreMap | null>(null);

    useEffect(() => {
        select.current = onSelect;
    }, [onSelect]);

    useEffect(() => {
        if (container.current === null) {
            return;
        }

        const map = createGeoMap({
            container: container.current,
            basemap,
            bounds: bounds ?? [2.7, 4.2, 14.7, 13.9],
            padding: 40,
        });

        map.on('load', () => {
            map.addSource('lgas', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({ id: 'lga-fill', type: 'fill', source: 'lgas', paint: { 'fill-color': '#FFFFFF', 'fill-opacity': basemap === null ? 0.55 : 0.12 } });
            map.addLayer({ id: 'lga-line', type: 'line', source: 'lgas', paint: { 'line-color': '#0E7C72', 'line-opacity': 0.45, 'line-width': 1.2 } });
            map.addLayer({
                id: 'lga-label',
                type: 'symbol',
                source: 'lgas',
                minzoom: 8,
                layout: { 'text-field': ['get', 'name'], 'text-size': 11, ...(basemap === null ? {} : { 'text-font': BASEMAP_FONT }) },
                paint: { 'text-color': '#3B4A45', 'text-halo-color': '#FFFFFF', 'text-halo-width': 1.5 },
            });

            map.addSource('units', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({
                id: 'unit',
                type: 'circle',
                source: 'units',
                paint: {
                    'circle-color': ['match', ['get', 'state'], 'confirmed', UNIT_COLOUR.confirmed, 'moved', UNIT_COLOUR.moved, 'not_found', UNIT_COLOUR.not_found, UNIT_COLOUR.pending],
                    'circle-radius': ['interpolate', ['linear'], ['zoom'], 7, 2.5, 12, 5, 16, 8],
                    'circle-stroke-color': '#FFFFFF',
                    'circle-stroke-width': ['interpolate', ['linear'], ['zoom'], 7, 0.5, 12, 1.5],
                },
            });

            map.on('click', 'unit', (e: MapLayerMouseEvent) => {
                const id = e.features?.[0]?.properties.id as number | undefined;

                if (id !== undefined) {
                    select.current(id);
                }
            });
            map.on('mouseenter', 'unit', () => { map.getCanvas().style.cursor = 'pointer'; });
            map.on('mouseleave', 'unit', () => { map.getCanvas().style.cursor = ''; });
            setLoaded(map);

            void fetch(url, { headers: { Accept: 'application/json' } })
                .then(async (r) => (r.ok ? ((await r.json()) as GeoJSON.FeatureCollection & { lgas: GeoJSON.FeatureCollection }) : null))
                .then((data) => {
                    if (data === null) {
                        return;
                    }

                    void map.getSource<GeoJSONSource>('lgas')?.setData(data.lgas);
                    void map.getSource<GeoJSONSource>('units')?.setData({ type: 'FeatureCollection', features: data.features });
                })
                .catch(() => undefined);
        });

        // The box can still be settling when the map is made (fonts, the
        // aside beside it), and a map measures itself only once.
        const resize = new ResizeObserver(() => { map.resize(); });
        resize.observe(container.current);

        return () => {
            resize.disconnect();
            map.remove();
            setLoaded(null);
        };
    }, [url, bounds, basemap]);

    return (
        <div className="relative h-full w-full">
            <div ref={container} className="h-full w-full" />
            <MapPanel map={loaded} layers={UNIT_LAYERS} />
        </div>
    );
}
