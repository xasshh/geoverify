import { layers, namedFlavor } from '@protomaps/basemaps';
import type { StyleSpecification } from 'maplibre-gl';
import { registerProtocol } from '@/lib/pmtilesProtocol';

/**
 * The street basemap every staff map can stand on.
 *
 * OpenStreetMap as Protomaps packages it, served from this application
 * (`/basemap/nigeria.pmtiles`, fetched by `geoverify:basemap-fetch`), with its
 * fonts and icons under `/basemap-assets`. Nothing here reaches a third party
 * while a map is open, so no tile bill grows with the number of people looking.
 *
 * The server shares `basemap` as null until it has the file. A map then gets
 * the plain ground it had before, rather than a request that is going to 404.
 *
 * A page adds its own sources and layers on top after `load`, as before; the
 * basemap's layers all come first, so whatever a page adds draws over them.
 */

export interface Basemap {
    url: string;
    attribution: string;
}

export type BasemapFlavor = 'light' | 'dark' | 'grayscale';

interface Options {
    basemap: Basemap | null;
    flavor?: BasemapFlavor;
    /** The colour used when there is no basemap to draw. */
    ground?: string;
}

export function basemapStyle({ basemap, flavor = 'light', ground = '#0F1A17' }: Options): StyleSpecification {
    if (basemap === null) {
        return {
            version: 8,
            sources: {},
            layers: [{ id: 'ground', type: 'background', paint: { 'background-color': ground } }],
        };
    }

    registerProtocol();

    const origin = window.location.origin;

    return {
        version: 8,
        // Absolute, because MapLibre resolves these in its worker, where a
        // path on its own has nothing to be relative to.
        glyphs: `${origin}/basemap-assets/fonts/{fontstack}/{range}.pbf`,
        sprite: `${origin}/basemap-assets/sprites/${flavor === 'dark' ? 'dark' : 'light'}`,
        sources: {
            protomaps: {
                type: 'vector',
                url: `pmtiles://${new URL(basemap.url, origin).toString()}`,
                attribution: basemap.attribution,
            },
        },
        layers: layers('protomaps', namedFlavor(flavor), { lang: 'en' }),
    };
}

/** The font the basemap ships, for a page's own labels to match it. */
export const BASEMAP_FONT = ['Noto Sans Medium'];
