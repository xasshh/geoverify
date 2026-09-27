import { useEffect, useMemo, useRef, useState } from 'react';
import { Map as MapLibre, type GeoJSONSource } from 'maplibre-gl';
import { Protocol } from 'pmtiles';
import { addProtocol } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';
import { fieldStyle, readPalette } from '@/lib/mapStyle';
import { openPack } from '@/lib/offline/pack';
import type { LocalPack } from '@/lib/offline/db';
import type { Fix } from '@/lib/geolocation';
import { cx } from '@/lib/cx';

/**
 * The pmtiles protocol, registered once for the life of the tab.
 *
 * MapLibre resolves protocols from a module level registry, so registering per
 * map would either throw or quietly replace the handler for a map still using it.
 */
const protocol = new Protocol();
let registered = false;

function registerProtocol(): Protocol {
    if (!registered) {
        addProtocol('pmtiles', protocol.tile);
        registered = true;
    }

    return protocol;
}

/**
 * The layers drawn out of the pack archive, as opposed to the officer's own
 * position, track and captures, which are GeoJSON held in memory.
 */
const PACK_LAYERS = [
    'wards',
    'cells-elsewhere',
    'roads-casing',
    'roads',
    'footprints',
    'footprints-outline',
    'cell-boundary',
];

export interface CapturedPoint {
    clientUuid: string;
    longitude: number;
    latitude: number;
}

interface FieldMapProps {
    pack: LocalPack;
    /** The cell being worked. Everything outside it is drawn back. */
    assignedH3: string;
    centre: [number, number];
    position: Fix | null;
    track: Array<[number, number]>;
    captured: CapturedPoint[];
    /** Footprints already done, so a reload does not undo what the officer sees. */
    visitedFootprintIds: number[];
    selectedFootprintId: number | null;
    onSelectFootprint: (id: number | null) => void;
    /** The street under the officer, read off the map and shown in the chrome. */
    onStreetChange: (street: string | null) => void;
}

export function FieldMap({
    pack,
    assignedH3,
    centre,
    position,
    track,
    captured,
    visitedFootprintIds,
    selectedFootprintId,
    onSelectFootprint,
    onStreetChange,
}: FieldMapProps) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<MapLibre | null>(null);
    const [ready, setReady] = useState(false);
    const [failed, setFailed] = useState<string | null>(null);

    /**
     * The footprint whose selected flag is currently set.
     *
     * removeFeatureState with a source and a sourceLayer but no id throws, and
     * clearing the whole layer would take the visited flags with it, so the
     * previous id is remembered and cleared by name.
     */
    const lastSelected = useRef<number | null>(null);

    // Whether the archive can be opened at all is knowable from the pack itself,
    // so it is derived rather than discovered in an effect and pushed to state.
    const opened = useMemo(() => openPack(pack), [pack]);

    /**
     * Whether the map is still following the officer.
     *
     * It stops the moment they pan, because an officer looking at the next street
     * does not want the map yanked back under their thumb every two seconds.
     */
    const following = useRef(true);

    useEffect(() => {
        const element = container.current;

        if (element === null) {
            return;
        }

        if (opened === null) {
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
            zoom: 16.5,
            maxZoom: pack.maxZoom + 2,
            minZoom: pack.minZoom,
            maxBounds: pack.bounds,
            // Nothing to attribute to: the pack was built from data whose
            // provenance is recorded in docs/geodata.md, not from a tile vendor.
            attributionControl: false,
            // A field officer holds the phone in one hand. Rotation by accident
            // is disorienting and there is nothing here that needs it.
            dragRotate: false,
            pitchWithRotate: false,
            touchZoomRotate: true,
        });

        instance.touchZoomRotate.disableRotation();
        instance.on('dragstart', () => {
            following.current = false;
        });
        instance.on('load', () => {
            setReady(true);
        });
        instance.on('idle', () => {
            // How many pack features are on screen, published to the DOM so it
            // can be read from outside. A MapLibre canvas is created without
            // preserveDrawingBuffer, so reading its pixels back gives a blank
            // image, and this is the only honest signal that the archive
            // decoded into tiles rather than into an empty map.
            element.dataset.drawn = String(
                instance.queryRenderedFeatures({
                    layers: PACK_LAYERS.filter((layer) => instance.getLayer(layer) !== undefined),
                }).length,
            );
        });
        instance.on('error', (event) => {
            // Reported rather than swallowed. A map that silently draws nothing
            // is worse than one that says it could not open.
            setFailed(event.error.message);
        });

        map.current = instance;

        return () => {
            instance.remove();
            map.current = null;
            lastSelected.current = null;
            setReady(false);
        };
    }, [opened, pack.maxZoom, pack.minZoom, pack.bounds, pack.layers, assignedH3, centre]);

    /** The officer's position, the ring around it, and the path walked. */
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        const here = instance.getSource<GeoJSONSource>('here');
        const path = instance.getSource<GeoJSONSource>('trace');

        if (here !== undefined) {
            void here.setData({
                type: 'FeatureCollection',
                features:
                    position === null
                        ? []
                        : [
                              {
                                  type: 'Feature',
                                  geometry: {
                                      type: 'Point',
                                      coordinates: [position.longitude, position.latitude],
                                  },
                                  properties: { accuracy: position.accuracy_m ?? 10 },
                              },
                          ],
            });
        }

        if (path !== undefined) {
            void path.setData({
                type: 'FeatureCollection',
                features:
                    track.length < 2
                        ? []
                        : [
                              {
                                  type: 'Feature',
                                  geometry: { type: 'LineString', coordinates: track },
                                  properties: {},
                              },
                          ],
            });
        }

        if (position !== null && following.current) {
            instance.easeTo({
                center: [position.longitude, position.latitude],
                duration: 600,
            });
        }

        // Which street the officer is standing on, answered from the pack rather
        // than from a geocoder there is no signal to reach.
        if (position !== null && (pack.layers.roads ?? 0) > 0) {
            const point = instance.project([position.longitude, position.latitude]);
            const near = instance.queryRenderedFeatures(
                [
                    [point.x - 24, point.y - 24],
                    [point.x + 24, point.y + 24],
                ],
                { layers: ['roads'] },
            );

            const named = near.find((feature) => typeof feature.properties.name === 'string');
            onStreetChange(named === undefined ? null : (named.properties.name as string));
        }
    }, [ready, position, track, pack.layers, onStreetChange]);

    /** Buildings captured on this device, including ones no footprint knew about. */
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        const source = instance.getSource<GeoJSONSource>('captured');

        if (source !== undefined) {
            void source.setData({
                type: 'FeatureCollection',
                features: captured.map((point) => ({
                    type: 'Feature',
                    geometry: { type: 'Point', coordinates: [point.longitude, point.latitude] },
                    properties: { clientUuid: point.clientUuid },
                })),
            });
        }
    }, [ready, captured]);

    /** Done and selected, carried in feature state so the pack stays untouched. */
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        for (const id of visitedFootprintIds) {
            instance.setFeatureState({ source: 'pack', sourceLayer: 'footprints', id }, {
                visited: true,
            });
        }
    }, [ready, visitedFootprintIds]);

    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        if (lastSelected.current !== null) {
            instance.removeFeatureState(
                { source: 'pack', sourceLayer: 'footprints', id: lastSelected.current },
                'selected',
            );
        }

        if (selectedFootprintId !== null) {
            instance.setFeatureState(
                { source: 'pack', sourceLayer: 'footprints', id: selectedFootprintId },
                { selected: true },
            );
        }

        lastSelected.current = selectedFootprintId;
    }, [ready, selectedFootprintId]);

    /** Tapping a building selects it. Tapping nothing clears the selection. */
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        const onClick = (event: { point: { x: number; y: number } }) => {
            // A thumb is not a pixel. The tap is widened so a small kiosk
            // footprint is selectable without pinching in first.
            const hits = instance.queryRenderedFeatures(
                [
                    [event.point.x - 12, event.point.y - 12],
                    [event.point.x + 12, event.point.y + 12],
                ],
                { layers: ['footprints'] },
            );

            const hit = hits[0];
            onSelectFootprint(hit === undefined ? null : Number(hit.id));
        };

        instance.on('click', onClick);

        return () => {
            instance.off('click', onClick);
        };
    }, [ready, onSelectFootprint]);

    return (
        <div className="absolute inset-0">
            <div ref={container} className="h-full w-full" data-testid="field-map" />

            {opened === null && (
                <div className="absolute inset-0 flex items-center justify-center bg-sunken/90 p-6">
                    <p className="max-w-[34ch] text-center text-ui text-amber-ink">
                        The map pack on this device is incomplete.
                    </p>
                </div>
            )}

            {/* A drawing error is worth saying, but it is not worth hiding a map
                that is otherwise working, so it sits in a strip under the top
                chrome rather than over the whole cell. */}
            {opened !== null && failed !== null && (
                <div
                    data-testid="map-error"
                    className="pointer-events-none absolute inset-x-0 top-12 px-2.5"
                >
                    <p className="max-w-[36ch] rounded-sm bg-surface/85 px-2 py-1 text-ui text-amber-ink backdrop-blur-sm">
                        {failed}
                    </p>
                </div>
            )}

            {position !== null && (
                <button
                    type="button"
                    className={cx(
                        'absolute top-1/2 right-2 -translate-y-1/2 rounded-sm border border-rule-strong',
                        'bg-surface/90',
                        'px-3 py-2 text-label text-muted backdrop-blur-sm',
                    )}
                    onClick={() => {
                        following.current = true;
                        map.current?.easeTo({
                            center: [position.longitude, position.latitude],
                            zoom: 17,
                            duration: 500,
                        });
                    }}
                >
                    Centre
                </button>
            )}
        </div>
    );
}
