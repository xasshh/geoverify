import { useEffect, useRef } from 'react';
import { Map as MapLibreMap, type GeoJSONSource, type MapLayerMouseEvent } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';
import { UNIT_COLOUR } from '@/lib/pollingUnits';

/**
 * Every polling unit on a plain ground with the LGAs outlined: no tiles from
 * anybody else's server, the way the directory map is drawn. A dot is where
 * an officer confirmed the unit, or where the list puts it while nobody has.
 */
export function PollingUnitMap({ url, bounds, onSelect }: { url: string; bounds: [number, number, number, number] | null; onSelect: (id: number) => void }) {
    const container = useRef<HTMLDivElement>(null);
    const select = useRef(onSelect);

    useEffect(() => {
        select.current = onSelect;
    }, [onSelect]);

    useEffect(() => {
        if (container.current === null) {
            return;
        }

        const map = new MapLibreMap({
            container: container.current,
            style: { version: 8, sources: {}, layers: [{ id: 'ground', type: 'background', paint: { 'background-color': '#EEF1EC' } }] },
            bounds: bounds ?? [2.7, 4.2, 14.7, 13.9],
            fitBoundsOptions: { padding: 40 },
            maxPitch: 0,
            attributionControl: false,
        });

        map.on('load', () => {
            map.addSource('lgas', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({ id: 'lga-fill', type: 'fill', source: 'lgas', paint: { 'fill-color': '#FFFFFF', 'fill-opacity': 0.55 } });
            map.addLayer({ id: 'lga-line', type: 'line', source: 'lgas', paint: { 'line-color': '#0E7C72', 'line-opacity': 0.45, 'line-width': 1.2 } });
            map.addLayer({
                id: 'lga-label',
                type: 'symbol',
                source: 'lgas',
                minzoom: 8,
                layout: { 'text-field': ['get', 'name'], 'text-size': 11 },
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
        };
    }, [url, bounds]);

    return <div ref={container} className="h-full w-full" />;
}
