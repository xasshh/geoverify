import { layers, namedFlavor } from '@protomaps/basemaps';
import type { LayerSpecification, Map as MapLibreMap } from 'maplibre-gl';
import { registerProtocol } from '@/lib/pmtilesProtocol';
import type { PMTiles } from 'pmtiles';

/**
 * The offline street map drawn into an officer's map: the same OpenStreetMap
 * style the console uses, slotted around the work list rather than over it.
 *
 * Ground, water, parks and roads go under the pack, so the cells and the
 * buildings still read first. Names and landmarks go above the buildings and
 * below the officer's own position, so a street name is never hidden by a
 * footprint and never sits on top of "you are here".
 *
 * The pack's own roads stay in the style at no opacity: the street shown in the
 * chrome is read from them by queryRenderedFeatures, which needs them drawn.
 * Its buildings are left out of the street map; the pack's footprints are the
 * work list and two sets of outlines would argue.
 *
 * Fonts and icons come from /basemap-assets, which the service worker answers
 * from its cache once the street map has been downloaded.
 */

/** Pack layers the street map replaces visually but which must stay queryable. */
const PACK_ROADS = ['roads-casing', 'roads'];

/** The officer's own layers, which the street names stay below. */
const OFFICER_LAYERS = ['captured-here', 'here-accuracy', 'here', 'trace'];

const SKIPPED = new Set(['background', 'buildings']);

export function addStreetLayers(map: MapLibreMap, opened: { archive: PMTiles; url: string }): string[] {
    if (map.getSource('streets') !== undefined) {
        return streetLayerIds(map);
    }

    registerProtocol().add(opened.archive);

    const origin = window.location.origin;
    map.setGlyphs(`${origin}/basemap-assets/fonts/{fontstack}/{range}.pbf`);
    map.setSprite(`${origin}/basemap-assets/sprites/light`);

    map.addSource('streets', { type: 'vector', url: `pmtiles://${opened.url}` });

    const existing = map.getStyle().layers.map((layer) => layer.id);
    // Under everything the pack draws: the first layer that is neither the
    // ground nor the satellite image.
    const under = existing.find((id) => id !== 'ground' && id !== 'imagery');
    const labelsUnder = existing.find((id) => OFFICER_LAYERS.includes(id));

    const added: string[] = [];

    for (const layer of layers('streets', namedFlavor('light'), { lang: 'en' })) {
        if (SKIPPED.has(layer.id)) {
            continue;
        }

        const spec: LayerSpecification = { ...layer, id: `streets-${layer.id}` };
        map.addLayer(spec, layer.type === 'symbol' ? labelsUnder : under);
        added.push(spec.id);
    }

    for (const id of PACK_ROADS) {
        if (map.getLayer(id) !== undefined) {
            map.setPaintProperty(id, 'line-opacity', 0);
        }
    }

    return added;
}

export function streetLayerIds(map: MapLibreMap): string[] {
    return map
        .getStyle()
        .layers.map((layer) => layer.id)
        .filter((id) => id.startsWith('streets-'));
}

/**
 * Over the satellite image only the names stay: the drawn ground and roads
 * would cover the photograph the officer asked to see.
 */
export function showStreetsWithSatellite(map: MapLibreMap, satellite: boolean): void {
    for (const layer of map.getStyle().layers) {
        if (layer.id.startsWith('streets-') && layer.type !== 'symbol') {
            map.setLayoutProperty(layer.id, 'visibility', satellite ? 'none' : 'visible');
        }
    }
}
