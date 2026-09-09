import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { ConsoleShell } from '@/components/ConsoleShell';
import {
    Map as MapLibreMap,
    NavigationControl,
    ScaleControl,
    type ExpressionSpecification,
    type GeoJSONSource,
    type MapLayerMouseEvent,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';
import { cx } from '@/lib/cx';

interface Summary {
    cells: number;
    footprints: number;
    captured: number;
    accepted: number;
    cellsWithFootprints: number;
    busiest: number;
    medianPerCell: number;
    wards: number;
    bounds: [number, number, number, number];
}

interface Area {
    id: number;
    name: string;
    client: string;
    contractRef: string | null;
    lgaCode: string | null;
    resolution: number;
}

interface CoverageProps {
    area: Area;
    summary: Summary;
}

/** What the cells endpoint puts on each feature. */
interface CellProperties {
    h3: string;
    status: string;
    footprints: number;
    captured: number;
    accepted: number;
    coverage: number;
    verified: number;
}

function readCell(properties: unknown): CellProperties | null {
    if (typeof properties !== 'object' || properties === null) {
        return null;
    }

    const p = properties as Partial<Record<keyof CellProperties, unknown>>;

    return {
        h3: typeof p.h3 === 'string' ? p.h3 : '',
        status: typeof p.status === 'string' ? p.status : '',
        footprints: typeof p.footprints === 'number' ? p.footprints : 0,
        captured: typeof p.captured === 'number' ? p.captured : 0,
        accepted: typeof p.accepted === 'number' ? p.accepted : 0,
        coverage: typeof p.coverage === 'number' ? p.coverage : 0,
        verified: typeof p.verified === 'number' ? p.verified : 0,
    };
}

/**
 * Cell fill by footprint density.
 *
 * The view the map opens on, because it is what proves the grid and the
 * denominator landed on real ground, and because completion shading over an
 * unstarted mandate is a screen of nothing. The scale is gold at increasing
 * opacity so it stays one hue, and an empty cell is drawn as outline only
 * rather than as a colour, because "no detected buildings" is an absence and
 * should look like one.
 */
const DENSITY_FILL: ExpressionSpecification = [
    'interpolate',
    ['linear'],
    ['get', 'footprints'],
    0,
    'rgba(0,0,0,0)',
    1,
    'rgba(208,174,99,0.18)',
    25,
    'rgba(208,174,99,0.38)',
    100,
    'rgba(208,174,99,0.62)',
    300,
    'rgba(208,174,99,0.85)',
];

/**
 * Completion against that denominator, one hue per question.
 *
 * Gold for captured and green for verified, deliberately not one scale with two
 * ends. "Has anyone been there" and "do we believe it" are different questions,
 * and a mandate that is 90 per cent captured and 20 per cent verified has to
 * look wrong at a glance rather than merely paler.
 */
function completionFill(property: 'coverage' | 'verified', rgb: string): ExpressionSpecification {
    return [
        'interpolate',
        ['linear'],
        ['get', property],
        0,
        'rgba(0,0,0,0)',
        1,
        `rgba(${rgb},0.20)`,
        50,
        `rgba(${rgb},0.50)`,
        100,
        `rgba(${rgb},0.85)`,
    ];
}

const SHADINGS = {
    density: {
        label: 'Building density',
        legend: 'Detected buildings per cell',
        ramp: 'from-transparent to-gold',
        low: '0',
        high: '300+',
        fill: DENSITY_FILL,
    },
    coverage: {
        label: 'Captured',
        legend: 'Captured against detected',
        ramp: 'from-transparent to-gold',
        low: '0%',
        high: '100%',
        fill: completionFill('coverage', '208,174,99'),
    },
    verified: {
        label: 'Verified',
        legend: 'Accepted against detected',
        ramp: 'from-transparent to-green',
        low: '0%',
        high: '100%',
        fill: completionFill('verified', '107,143,110'),
    },
} as const;

type Shading = keyof typeof SHADINGS;

export default function Coverage({ area, summary }: CoverageProps) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<MapLibreMap | null>(null);
    const [loadedCells, setLoadedCells] = useState(0);
    const [hovered, setHovered] = useState<CellProperties | null>(null);
    const [shading, setShading] = useState<Shading>('density');

    useEffect(() => {
        if (container.current === null || map.current !== null) {
            return;
        }

        const instance = new MapLibreMap({
            container: container.current,
            // No basemap. Self-hosted vector tiles arrive with the offline packs at
            // M5; billed tile providers are out of the question. What is drawn here
            // is this system's own geometry, which is the thing under test.
            style: {
                version: 8,
                sources: {},
                layers: [
                    {
                        id: 'ground',
                        type: 'background',
                        paint: { 'background-color': '#0E1E2E' },
                    },
                ],
            },
            bounds: summary.bounds,
            fitBoundsOptions: { padding: 48 },
            maxPitch: 0,
            attributionControl: false,
        });

        map.current = instance;

        instance.addControl(new NavigationControl({ showCompass: false }), 'top-right');
        instance.addControl(new ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-left');

        instance.on('load', () => {
            void (async () => {
                const [boundary, cells] = await Promise.all([
                    fetch(`/console/coverage/${String(area.id)}/boundary.geojson`).then(
                        (r) => r.json() as Promise<GeoJSON.Geometry>,
                    ),
                    fetch(`/console/coverage/${String(area.id)}/cells.geojson`).then(
                        (r) => r.json() as Promise<GeoJSON.FeatureCollection>,
                    ),
                ]);

                /*
                 * Roads first, so the grid sits over them. A supervisor reads a
                 * low completion figure differently once they can see the cell
                 * is the far side of an expressway, and the register's own
                 * PostGIS is where the network comes from: no basemap, no
                 * billed tile provider.
                 */
                instance.addSource('roads', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] },
                });
                instance.addLayer({
                    id: 'roads',
                    type: 'line',
                    source: 'roads',
                    layout: { 'line-cap': 'round', 'line-join': 'round' },
                    paint: {
                        'line-color': [
                            'match',
                            ['get', 'highway'],
                            ['motorway', 'trunk'],
                            '#8A7340',
                            ['primary'],
                            '#4C5C68',
                            '#31404D',
                        ],
                        'line-width': [
                            'interpolate',
                            ['linear'],
                            ['zoom'],
                            8,
                            ['match', ['get', 'highway'], ['motorway', 'trunk'], 1.2, 0.4],
                            14,
                            ['match', ['get', 'highway'], ['motorway', 'trunk'], 4.5, 1.6],
                        ],
                    },
                });

                instance.addSource('cells', { type: 'geojson', data: cells });
                instance.addSource('mandate', {
                    type: 'geojson',
                    data: { type: 'Feature', geometry: boundary, properties: {} },
                });

                instance.addLayer({
                    id: 'cell-fill',
                    type: 'fill',
                    source: 'cells',
                    paint: { 'fill-color': SHADINGS.density.fill },
                });

                instance.addLayer({
                    id: 'cell-line',
                    type: 'line',
                    source: 'cells',
                    paint: {
                        'line-color': '#A2B0BB',
                        'line-width': ['interpolate', ['linear'], ['zoom'], 10, 0.15, 15, 0.6],
                        'line-opacity': 0.35,
                    },
                });

                // The mandate edge: the hard limit of the contracted ground.
                instance.addLayer({
                    id: 'mandate-line',
                    type: 'line',
                    source: 'mandate',
                    paint: { 'line-color': '#4BB8B0', 'line-width': 1.8 },
                });

                setLoadedCells(cells.features.length);

                // Fetched after the grid has painted. Eighteen thousand cells
                // are what this screen is about; the streets are context that
                // can arrive a moment later.
                void fetch(`/console/coverage/${String(area.id)}/roads.json`)
                    .then((response) => response.json() as Promise<{ roads: GeoJSON.FeatureCollection }>)
                    .then((payload) => {
                        const source = instance.getSource<GeoJSONSource>('roads');

                        if (source !== undefined) {
                            void source.setData(payload.roads);
                        }
                    })
                    .catch(() => {
                        // A mandate with no roads loaded still draws its grid.
                    });

                instance.on('mousemove', 'cell-fill', (event: MapLayerMouseEvent) => {
                    setHovered(readCell(event.features?.[0]?.properties));
                    instance.getCanvas().style.cursor = 'crosshair';
                });

                instance.on('mouseleave', 'cell-fill', () => {
                    setHovered(null);
                    instance.getCanvas().style.cursor = '';
                });
            })();
        });

        return () => {
            instance.remove();
            map.current = null;
        };
    }, [area.id, summary.bounds]);

    // Repainted rather than rebuilt: a mandate is 18,337 cells and tearing the
    // map down to change a colour ramp would refetch every one of them.
    useEffect(() => {
        const instance = map.current;

        if (instance === null || instance.getLayer('cell-fill') === undefined) {
            return;
        }

        instance.setPaintProperty('cell-fill', 'fill-color', SHADINGS[shading].fill);
    }, [shading, loadedCells]);

    const stats: Array<[string, string]> = [
        ['Cells', summary.cells.toLocaleString()],
        ['Footprints', summary.footprints.toLocaleString()],
        ['Captured', summary.captured.toLocaleString()],
        ['Accepted', summary.accepted.toLocaleString()],
        ['Cells with buildings', summary.cellsWithFootprints.toLocaleString()],
        ['Median per occupied cell', summary.medianPerCell.toLocaleString()],
        ['Busiest cell', summary.busiest.toLocaleString()],
        ['Wards intersecting', summary.wards.toLocaleString()],
    ];

    return (
        <ConsoleShell current="coverage" mode="dusk" fill>
            <Head title={`Coverage: ${area.name}`} />

            <header className="shrink-0 border-b border-rule px-6 py-3">
                <div className="flex flex-wrap items-baseline justify-between gap-4">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            Coverage
                        </p>
                        <h1 className="font-display text-display-m text-ink">{area.name}</h1>
                    </div>
                    <dl className="flex flex-wrap gap-x-8 gap-y-2">
                        {stats.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-label font-semibold tracking-[0.12em] text-faint uppercase">
                                    {label}
                                </dt>
                                <dd className="numeric-mono text-body text-ink">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
                <p className="mt-2 numeric-mono text-mono text-faint">
                    {area.client}
                    {area.contractRef !== null && ` / ${area.contractRef}`} / {area.lgaCode} / H3
                    res {area.resolution}
                </p>
            </header>

            <div className="relative min-h-0 flex-1">
                {/* Sized explicitly rather than by absolute positioning: MapLibre's
                    own stylesheet sets .maplibregl-map to position:relative at the
                    same specificity, so an absolutely positioned container collapses
                    to zero height depending on stylesheet order. */}
                <div ref={container} className="h-full w-full" />

                <div className="pointer-events-none absolute top-4 left-4 max-w-xs rounded-sm border border-rule-strong bg-surface/95 p-3">
                    <p className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        {SHADINGS[shading].legend}
                    </p>
                    <div className="mt-2 flex items-center gap-2">
                        <span
                            className={cx(
                                'h-3 flex-1 rounded-[2px] bg-gradient-to-r',
                                SHADINGS[shading].ramp,
                            )}
                        />
                    </div>
                    <div className="mt-1 flex justify-between numeric-mono text-label text-faint">
                        <span>{SHADINGS[shading].low}</span>
                        <span>{SHADINGS[shading].high}</span>
                    </div>
                    {/* The panel is click through so the map can be panned
                        underneath it. The buttons have to take their clicks
                        back, or they are visible and dead. */}
                    <div className="pointer-events-auto mt-3 flex flex-wrap gap-1.5">
                        {(Object.keys(SHADINGS) as Shading[]).map((key) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => {
                                    setShading(key);
                                }}
                                aria-pressed={shading === key}
                                className={cx(
                                    'rounded-sm border px-2.5 py-1 text-label',
                                    shading === key
                                        ? 'border-gold text-ink'
                                        : 'border-rule text-muted hover:border-rule-strong',
                                )}
                            >
                                {SHADINGS[key].label}
                            </button>
                        ))}
                    </div>

                    <p className="mt-2 text-label text-faint">
                        {loadedCells.toLocaleString()} cells drawn. Outline only means no
                        detected buildings, or nothing counted yet under this shading.
                    </p>
                </div>

                {hovered !== null && (
                    <div className="pointer-events-none absolute right-4 bottom-4 rounded-sm border border-rule-strong bg-surface/95 p-3">
                        <p className="numeric-mono text-mono text-ink">{hovered.h3}</p>
                        <p className="mt-1 numeric-mono text-label text-muted">
                            {hovered.footprints.toLocaleString()} detected /{' '}
                            {hovered.captured.toLocaleString()} captured /{' '}
                            <span className="text-green">
                                {hovered.accepted.toLocaleString()} accepted
                            </span>
                        </p>
                        <p className="mt-0.5 text-label text-faint">{hovered.status}</p>
                    </div>
                )}
            </div>
        </ConsoleShell>
    );
}
