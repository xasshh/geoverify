import type { StyleSpecification } from 'maplibre-gl';

/**
 * The field map's style.
 *
 * Built in code rather than held as a JSON file so it reads the same palette the
 * rest of the interface does. The officer can be in daylight or dusk mode and the
 * map follows, which matters more than it sounds: a bright map at night in a
 * compound with no street lighting is the difference between seeing the screen
 * and not.
 *
 * There is no text on the map. Labels need SDF glyph files, which is a whole
 * pipeline to ship offline, and 8pt street names on a five inch screen in Abuja
 * sun are not what an officer reads anyway. The street they are standing on is
 * named in the chrome instead, where it is legible.
 */

export interface Palette {
    surface: string;
    sunken: string;
    ink: string;
    muted: string;
    faint: string;
    rule: string;
    ruleStrong: string;
    gold: string;
    green: string;
    amber: string;
    graphite: string;
}

/**
 * Reads the palette off the document.
 *
 * One source of truth for colour: the CSS custom properties. A hex value written
 * twice is a hex value that will disagree with itself by the second milestone.
 */
export function readPalette(element: HTMLElement): Palette {
    const styles = getComputedStyle(element);
    const read = (name: string, fallback: string): string =>
        styles.getPropertyValue(name).trim() || fallback;

    return {
        surface: read('--gv-surface', '#0e1e2e'),
        sunken: read('--gv-surface-sunken', '#091624'),
        ink: read('--gv-text', '#f2f1ed'),
        muted: read('--gv-text-muted', '#a2b0bb'),
        faint: read('--gv-text-faint', '#7e8c99'),
        rule: read('--gv-rule', '#25394b'),
        ruleStrong: read('--gv-rule-strong', '#385064'),
        gold: read('--gv-gold', '#4bb8b0'),
        green: read('--gv-green', '#5cc0a4'),
        amber: read('--gv-amber', '#ee9c45'),
        graphite: read('--gv-graphite', '#a2b0bb'),
    };
}

interface StyleOptions {
    /** The pmtiles key of the pack on the device. */
    packUrl: string;
    palette: Palette;
    /** The cell the officer is assigned to. Everything else is dimmed. */
    assignedH3: string;
    maxZoom: number;
    /** Which layers the pack actually holds. A pack built without roads has none. */
    layers: Record<string, number>;
    /** Used only to size the accuracy ring in pixels. Display, not geodesy. */
    latitude: number;
}

/** Metres per pixel at zoom 0 for a given latitude, which is what sizes the ring. */
function metresPerPixel(latitude: number): number {
    return (156543.03392 * Math.cos((latitude * Math.PI) / 180)) / 256;
}

export function fieldStyle(options: StyleOptions): StyleSpecification {
    const { packUrl, palette, assignedH3, maxZoom, layers, latitude } = options;
    const hasRoads = (layers.roads ?? 0) > 0;
    const perPixel = metresPerPixel(latitude);

    const style: StyleSpecification = {
        version: 8,
        // No sprite and no glyphs: both are network fetches, and there is no
        // network. Every layer here draws from geometry alone.
        sources: {
            pack: {
                type: 'vector',
                url: `pmtiles://${packUrl}`,
                // Lets the footprint id carry into feature state, which is how a
                // building the officer has already done stops looking undone.
                promoteId: { footprints: 'id' },
            },
            here: { type: 'geojson', data: { type: 'FeatureCollection', features: [] } },
            trace: { type: 'geojson', data: { type: 'FeatureCollection', features: [] } },
            captured: { type: 'geojson', data: { type: 'FeatureCollection', features: [] } },
        },
        layers: [
            { id: 'ground', type: 'background', paint: { 'background-color': palette.sunken } },

            {
                id: 'wards',
                type: 'line',
                source: 'pack',
                'source-layer': 'wards',
                paint: {
                    'line-color': palette.ruleStrong,
                    'line-width': 1,
                    'line-dasharray': [4, 3],
                },
            },

            // Everything outside the mandate cell is pushed back rather than
            // hidden. An officer needs to see that the next street exists and
                // that it is not theirs.
            {
                id: 'cells-elsewhere',
                type: 'fill',
                source: 'pack',
                'source-layer': 'cells',
                filter: ['!=', ['get', 'h3'], assignedH3],
                paint: { 'fill-color': palette.surface, 'fill-opacity': 0.55 },
            },
        ],
    };

    if (hasRoads) {
        style.layers.push(
            {
                id: 'roads-casing',
                type: 'line',
                source: 'pack',
                'source-layer': 'roads',
                filter: ['in', ['get', 'highway'], ['literal', ['primary', 'secondary', 'trunk']]],
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: {
                    'line-color': palette.sunken,
                    'line-width': ['interpolate', ['linear'], ['zoom'], 12, 3, 17, 12],
                },
            },
            {
                id: 'roads',
                type: 'line',
                source: 'pack',
                'source-layer': 'roads',
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: {
                    'line-color': [
                        'match',
                        ['get', 'highway'],
                        ['primary', 'trunk'],
                        palette.muted,
                        ['secondary', 'tertiary'],
                        palette.faint,
                        palette.rule,
                    ],
                    'line-width': [
                        'interpolate',
                        ['linear'],
                        ['zoom'],
                        12,
                        ['match', ['get', 'highway'], ['primary', 'trunk'], 2, 0.6],
                        17,
                        ['match', ['get', 'highway'], ['primary', 'trunk'], 8, 3],
                    ],
                },
            },
        );
    }

    style.layers.push(
        // The work list. Colour is state, not decoration: what is left to do is
        // the only thing this map is really for.
        {
            id: 'footprints',
            type: 'fill',
            source: 'pack',
            'source-layer': 'footprints',
            paint: {
                'fill-color': [
                    'case',
                    ['boolean', ['feature-state', 'selected'], false],
                    palette.gold,
                    ['any',
                        ['boolean', ['feature-state', 'visited'], false],
                        ['get', 'visited'],
                    ],
                    palette.green,
                    palette.graphite,
                ],
                'fill-opacity': [
                    'case',
                    ['boolean', ['feature-state', 'selected'], false],
                    0.55,
                    0.22,
                ],
            },
        },
        {
            id: 'footprints-outline',
            type: 'line',
            source: 'pack',
            'source-layer': 'footprints',
            paint: {
                'line-color': [
                    'case',
                    ['boolean', ['feature-state', 'selected'], false],
                    palette.gold,
                    ['any',
                        ['boolean', ['feature-state', 'visited'], false],
                        ['get', 'visited'],
                    ],
                    palette.green,
                    palette.graphite,
                ],
                'line-width': [
                    'case',
                    ['boolean', ['feature-state', 'selected'], false],
                    2.5,
                    0.8,
                ],
            },
        },

        // The assignment boundary, drawn over the footprints so it is never lost
        // under them.
        {
            id: 'cell-boundary',
            type: 'line',
            source: 'pack',
            'source-layer': 'cells',
            filter: ['==', ['get', 'h3'], assignedH3],
            paint: { 'line-color': palette.gold, 'line-width': 1.5, 'line-opacity': 0.8 },
        },

        // Buildings captured on this device that the pack has never heard of: a
        // kiosk, a new build, anything the footprint data missed.
        {
            id: 'captured-here',
            type: 'circle',
            source: 'captured',
            paint: {
                'circle-radius': 5,
                'circle-color': palette.green,
                'circle-stroke-width': 1.5,
                'circle-stroke-color': palette.sunken,
            },
        },

        {
            id: 'trace-line',
            type: 'line',
            source: 'trace',
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: { 'line-color': palette.gold, 'line-width': 2, 'line-opacity': 0.45 },
        },

        // The accuracy ring is the officer's warning that the fix is soft. Sized
        // from the reported accuracy so a 40 m ring looks like 40 m on the ground.
        {
            id: 'here-accuracy',
            type: 'circle',
            source: 'here',
            paint: {
                'circle-color': palette.gold,
                'circle-opacity': 0.1,
                'circle-stroke-color': palette.gold,
                'circle-stroke-width': 1,
                'circle-stroke-opacity': 0.35,
                'circle-radius': [
                    'interpolate',
                    ['exponential', 2],
                    ['zoom'],
                    0,
                    ['/', ['get', 'accuracy'], perPixel],
                    maxZoom + 6,
                    ['/', ['get', 'accuracy'], perPixel / 2 ** (maxZoom + 6)],
                ],
            },
        },
        {
            id: 'here',
            type: 'circle',
            source: 'here',
            paint: {
                'circle-radius': 6,
                'circle-color': palette.gold,
                'circle-stroke-width': 2,
                'circle-stroke-color': palette.sunken,
            },
        },
    );

    return style;
}
