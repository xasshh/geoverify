import {
    FullscreenControl,
    Map as MapLibreMap,
    NavigationControl,
    ScaleControl,
    type LngLatBoundsLike,
    type MapOptions,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';
import { basemapStyle, type Basemap, type BasemapFlavor } from '@/lib/basemap';
import { registerProtocol } from '@/lib/pmtilesProtocol';

/**
 * The one way a staff or client map is made.
 *
 * Every such map stands on the same street basemap, carries the same controls
 * and offers the same satellite view, so improving one improves them all. A page
 * still adds its own sources and layers on `load`, exactly as before: this
 * builds the ground, and the page draws what it is about on top.
 *
 * Not the officer's map. FieldMap and AreaMap belong to the shipped field
 * client and keep their own offline style.
 */

/** A satellite archive: a Sentinel mosaic of one mandate, built by BuildSatelliteBasemap. */
export interface Imagery {
    url: string;
    captured: string | null;
    maxZoom: number | null;
    licence: string | null;
}

export type MapView = 'street' | 'satellite';

export interface GeoMapOptions {
    container: HTMLElement;
    basemap: Basemap | null;
    /** Light for daylight screens, dark for the dusk console. */
    flavor?: BasemapFlavor;
    /** Ground colour when the server has no basemap. */
    ground?: string;
    bounds?: LngLatBoundsLike;
    padding?: number;
    maxFitZoom?: number;
    /** Satellite archives the view switch can show. None hides the switch. */
    imagery?: Imagery[];
    /** Where the zoom buttons go; the scale always sits bottom left. */
    controls?: 'top-right' | 'top-left' | 'none';
    fullscreen?: boolean;
    /** Anything else MapLibre takes, passed through. */
    options?: Partial<MapOptions>;
}

const IMAGERY_PREFIX = 'gv-imagery-';

export function createGeoMap({
    container,
    basemap,
    flavor = 'light',
    ground = '#EEF1EC',
    bounds,
    padding = 48,
    maxFitZoom = 16,
    imagery = [],
    controls = 'top-right',
    fullscreen = true,
    options = {},
}: GeoMapOptions): MapLibreMap {
    const map = new MapLibreMap({
        container,
        style: basemapStyle({ basemap, flavor, ground }),
        ...(bounds === undefined ? {} : { bounds, fitBoundsOptions: { padding, maxZoom: maxFitZoom } }),
        maxPitch: 0,
        attributionControl: basemap === null ? false : { compact: true },
        ...options,
    });

    if (controls !== 'none') {
        map.addControl(new NavigationControl({ showCompass: false }), controls);

        if (fullscreen) {
            map.addControl(new FullscreenControl(), controls);
        }
    }

    map.addControl(new ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-left');

    // Registered before the page's own load handler, so the imagery sits under
    // whatever the page draws. It goes below the basemap's labels, so a
    // satellite view still names its streets.
    map.on('load', () => {
        if (imagery.length === 0) {
            return;
        }

        registerProtocol();

        const firstLabel = map.getStyle().layers.find((layer) => layer.type === 'symbol')?.id;

        imagery.forEach((archive, index) => {
            const id = `${IMAGERY_PREFIX}${String(index)}`;

            map.addSource(id, {
                type: 'raster',
                url: `pmtiles://${new URL(archive.url, window.location.origin).toString()}`,
                tileSize: 256,
                ...(archive.licence === null ? {} : { attribution: archive.licence }),
            });
            map.addLayer(
                {
                    id,
                    type: 'raster',
                    source: id,
                    layout: { visibility: 'none' },
                    paint: { 'raster-fade-duration': 0 },
                },
                firstLabel,
            );
        });
    });

    return map;
}

/** Street or satellite, and how strongly the image shows. */
export function setMapView(map: MapLibreMap, view: MapView, opacity = 1): void {
    for (const layer of map.getStyle().layers) {
        if (layer.id.startsWith(IMAGERY_PREFIX)) {
            map.setLayoutProperty(layer.id, 'visibility', view === 'satellite' ? 'visible' : 'none');
            map.setPaintProperty(layer.id, 'raster-opacity', opacity);
        }
    }
}

/** Shows or hides a page's layers by id, ignoring any not drawn yet. */
export function setLayersVisible(map: MapLibreMap, ids: string[], visible: boolean): void {
    for (const id of ids) {
        if (map.getLayer(id) !== undefined) {
            map.setLayoutProperty(id, 'visibility', visible ? 'visible' : 'none');
        }
    }
}
