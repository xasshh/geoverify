import { useEffect, useMemo, useRef, useState } from 'react';
import { Map as MapLibre, type GeoJSONSource, type MapMouseEvent, type MapTouchEvent } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';
import { registerProtocol } from '@/lib/pmtilesProtocol';
import { fieldStyle, readPalette } from '@/lib/mapStyle';
import { openPack } from '@/lib/offline/pack';
import { openImagery, type BasemapChoice } from '@/lib/offline/imagery';
import type { LocalImagery, LocalPack } from '@/lib/offline/db';
import type { Fix } from '@/lib/geolocation';

export type DrawKind = 'point' | 'line' | 'polygon';

/** Within this many screen pixels a tap lands on an existing corner instead. */
const SNAP_PX = 14;

interface AreaMapProps {
    pack: LocalPack;
    imagery: LocalImagery | null;
    basemap: BasemapChoice;
    assignedH3: string;
    centre: [number, number];
    position: Fix | null;
    /** Features already recorded around these cells, shown and snapped to. */
    features: GeoJSON.FeatureCollection;
    /** The shape being drawn, as an ordered list of corners. */
    vertices: Array<[number, number]>;
    kind: DrawKind | null;
    colour: string;
    /** Taps add corners only while drawing by hand. */
    tapping: boolean;
    onAddVertex: (at: [number, number]) => void;
    onMoveVertex: (index: number, to: [number, number]) => void;
}

/**
 * The area capture map: the offline street pack, the satellite image when the
 * officer has it, what is already recorded, and the shape being drawn.
 *
 * Drawing is deliberately small: tap to add a corner (snapped to an existing
 * corner within a fingertip), drag a corner to move it. Undo, finishing and
 * walking live in the screen around it, which owns the vertices.
 */
export function AreaMap({
    pack,
    imagery,
    basemap,
    assignedH3,
    centre,
    position,
    features,
    vertices,
    kind,
    colour,
    tapping,
    onAddVertex,
    onMoveVertex,
}: AreaMapProps) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<MapLibre | null>(null);
    const [ready, setReady] = useState(false);
    const [failed, setFailed] = useState<string | null>(null);

    // Read through refs inside map handlers, which are bound once.
    const latest = useRef({ vertices, features, tapping, onAddVertex, onMoveVertex });

    useEffect(() => {
        latest.current = { vertices, features, tapping, onAddVertex, onMoveVertex };
    });

    const opened = useMemo(() => openPack(pack), [pack]);
    const openedImagery = useMemo(() => (imagery === null ? null : openImagery(imagery)), [imagery]);

    useEffect(() => {
        const element = container.current;

        if (element === null || opened === null) {
            return;
        }

        registerProtocol().add(opened.archive);

        const instance = new MapLibre({
            container: element,
            style: fieldStyle({
                packUrl: opened.url,
                palette: readPalette(element),
                assignedH3,
                maxZoom: pack.maxZoom,
                layers: pack.layers,
                latitude: centre[1],
            }),
            center: centre,
            zoom: 16,
            maxZoom: Math.max(pack.maxZoom + 2, 19),
            minZoom: Math.min(pack.minZoom, 10),
            attributionControl: false,
            dragRotate: false,
            pitchWithRotate: false,
        });

        instance.touchZoomRotate.disableRotation();

        instance.on('load', () => {
            instance.addSource('recorded', { type: 'geojson', data: latest.current.features });
            instance.addSource('draft', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });

            instance.addLayer({
                id: 'recorded-fill',
                type: 'fill',
                source: 'recorded',
                filter: ['==', ['geometry-type'], 'Polygon'],
                paint: { 'fill-color': ['get', 'colour'], 'fill-opacity': 0.28 },
            });
            instance.addLayer({
                id: 'recorded-line',
                type: 'line',
                source: 'recorded',
                filter: ['!=', ['geometry-type'], 'Point'],
                paint: { 'line-color': ['get', 'colour'], 'line-width': 2.5 },
            });
            instance.addLayer({
                id: 'recorded-point',
                type: 'circle',
                source: 'recorded',
                filter: ['==', ['geometry-type'], 'Point'],
                paint: { 'circle-radius': 6, 'circle-color': ['get', 'colour'], 'circle-stroke-color': '#fff', 'circle-stroke-width': 2 },
            });
            instance.addLayer({
                id: 'draft-fill',
                type: 'fill',
                source: 'draft',
                filter: ['==', ['geometry-type'], 'Polygon'],
                paint: { 'fill-color': ['get', 'colour'], 'fill-opacity': 0.3 },
            });
            instance.addLayer({
                id: 'draft-line',
                type: 'line',
                source: 'draft',
                filter: ['==', ['geometry-type'], 'LineString'],
                paint: { 'line-color': ['get', 'colour'], 'line-width': 3, 'line-dasharray': [2, 1] },
            });
            instance.addLayer({
                id: 'draft-vertices',
                type: 'circle',
                source: 'draft',
                filter: ['==', ['geometry-type'], 'Point'],
                paint: { 'circle-radius': 8, 'circle-color': '#ffffff', 'circle-stroke-color': ['get', 'colour'], 'circle-stroke-width': 3 },
            });
            // The officer's position draws through the style's own 'here'
            // source and layers, exactly as on the building screen.
            setReady(true);
        });

        instance.on('error', (event) => {
            setFailed(event.error.message);
        });

        // Drag a corner to move it. Pan is held while a corner is in hand.
        const dragging = { current: null as number | null };

        // Tap: a new corner, snapped to any corner within a fingertip.
        instance.on('click', (event: MapMouseEvent) => {
            if (!latest.current.tapping || dragging.current !== null) {
                return;
            }

            latest.current.onAddVertex(snap(instance, event.point, [event.lngLat.lng, event.lngLat.lat], latest.current));
        });

        const grab = (event: MapMouseEvent | MapTouchEvent) => {
            const hit = instance.queryRenderedFeatures(event.point, { layers: ['draft-vertices'] })[0];
            const index = hit?.properties.index as number | undefined;

            if (index === undefined) {
                return;
            }

            event.preventDefault();
            dragging.current = index;
            instance.dragPan.disable();
        };

        const move = (event: MapMouseEvent | MapTouchEvent) => {
            if (dragging.current === null) {
                return;
            }

            latest.current.onMoveVertex(dragging.current, [event.lngLat.lng, event.lngLat.lat]);
        };

        const drop = (event: MapMouseEvent | MapTouchEvent) => {
            if (dragging.current === null) {
                return;
            }

            const index = dragging.current;
            latest.current.onMoveVertex(index, snap(instance, event.point, [event.lngLat.lng, event.lngLat.lat], latest.current, index));
            // Released a tick later, so the click that ends a drag is not a new corner.
            window.setTimeout(() => {
                dragging.current = null;
            }, 0);
            instance.dragPan.enable();
        };

        instance.on('mousedown', 'draft-vertices', grab);
        instance.on('touchstart', 'draft-vertices', grab);
        instance.on('mousemove', move);
        instance.on('touchmove', move);
        instance.on('mouseup', drop);
        instance.on('touchend', drop);

        map.current = instance;

        return () => {
            instance.remove();
            map.current = null;
            setReady(false);
        };
    }, [opened, pack.maxZoom, pack.minZoom, pack.layers, assignedH3, centre]);

    // The satellite image, under everything drawn.
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready || openedImagery === null) {
            return;
        }

        if (instance.getSource('imagery') === undefined) {
            registerProtocol().add(openedImagery.archive);
            instance.addSource('imagery', { type: 'raster', url: `pmtiles://${openedImagery.url}`, tileSize: 256 });
            instance.addLayer(
                { id: 'imagery', type: 'raster', source: 'imagery', paint: { 'raster-fade-duration': 0 } },
                instance.getStyle().layers[1]?.id,
            );
        }

        instance.setLayoutProperty('imagery', 'visibility', basemap.basemap === 'satellite' ? 'visible' : 'none');
        instance.setPaintProperty('imagery', 'raster-opacity', basemap.opacity);
    }, [ready, openedImagery, basemap.basemap, basemap.opacity]);

    useEffect(() => {
        if (ready) {
            void map.current?.getSource<GeoJSONSource>('recorded')?.setData(features);
        }
    }, [ready, features]);

    // The shape being drawn: its corners, and the line or area they make.
    useEffect(() => {
        const source = ready ? map.current?.getSource<GeoJSONSource>('draft') : undefined;

        if (source === undefined) {
            return;
        }

        const drawn: GeoJSON.Feature[] = vertices.map((coordinates, index) => ({
            type: 'Feature',
            geometry: { type: 'Point', coordinates },
            properties: { index, colour },
        }));

        if (kind === 'line' && vertices.length >= 2) {
            drawn.unshift({ type: 'Feature', geometry: { type: 'LineString', coordinates: vertices }, properties: { colour } });
        }

        if (kind === 'polygon' && vertices.length >= 2) {
            drawn.unshift({
                type: 'Feature',
                geometry:
                    vertices.length >= 3
                        ? { type: 'Polygon', coordinates: [[...vertices, vertices[0] as [number, number]]] }
                        : { type: 'LineString', coordinates: vertices },
                properties: { colour },
            });
        }

        void source.setData({ type: 'FeatureCollection', features: drawn });
    }, [ready, vertices, kind, colour]);

    useEffect(() => {
        const source = ready ? map.current?.getSource<GeoJSONSource>('here') : undefined;

        void source?.setData({
            type: 'FeatureCollection',
            features:
                position === null
                    ? []
                    : [
                          {
                              type: 'Feature',
                              geometry: { type: 'Point', coordinates: [position.longitude, position.latitude] },
                              properties: { accuracy: position.accuracy_m ?? 10 },
                          },
                      ],
        });
    }, [ready, position]);

    if (opened === null || failed !== null) {
        return (
            <div className="flex h-full items-center justify-center p-6 text-center text-ui text-muted">
                {failed ?? 'The offline map is not on this phone yet. Download it from Sync & device.'}
            </div>
        );
    }

    return <div ref={container} className="h-full w-full" data-ready={ready ? '1' : '0'} />;
}

/** The nearest existing corner within a fingertip, or where the finger landed. */
function snap(
    map: MapLibre,
    point: { x: number; y: number },
    fallback: [number, number],
    state: { vertices: Array<[number, number]>; features: GeoJSON.FeatureCollection },
    except?: number,
): [number, number] {
    const nearest: { at: [number, number] | null; px: number } = { at: null, px: SNAP_PX };

    const consider = (c: [number, number]) => {
        const p = map.project(c);
        const d = Math.hypot(p.x - point.x, p.y - point.y);

        if (d < nearest.px) {
            nearest.px = d;
            nearest.at = c;
        }
    };

    state.vertices.forEach((v, i) => {
        if (i !== except) {
            consider(v);
        }
    });

    for (const feature of state.features.features) {
        corners(feature.geometry).forEach(consider);
    }

    return nearest.at ?? fallback;
}

function corners(geometry: GeoJSON.Geometry): Array<[number, number]> {
    switch (geometry.type) {
        case 'Point':
            return [geometry.coordinates as [number, number]];
        case 'LineString':
            return geometry.coordinates as Array<[number, number]>;
        case 'Polygon':
            return geometry.coordinates.flat() as Array<[number, number]>;
        case 'MultiPolygon':
            return geometry.coordinates.flat(2) as Array<[number, number]>;
        default:
            return [];
    }
}
