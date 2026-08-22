import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import {
    Map as MapLibreMap,
    NavigationControl,
    ScaleControl,
    type ExpressionSpecification,
    type MapLayerMouseEvent,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import '@/lib/maplibre';

interface Summary {
    cells: number;
    footprints: number;
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
    coverage: number;
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
        coverage: typeof p.coverage === 'number' ? p.coverage : 0,
    };
}

/**
 * Cell fill by footprint density.
 *
 * Density, not completion, because at this milestone nothing has been captured
 * yet: what the map has to prove is that the grid and the denominator landed on
 * real ground. The scale is gold at increasing opacity so it stays one hue, and
 * an empty cell is drawn as outline only rather than as a colour, because "no
 * detected buildings" is an absence and should look like one.
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

export default function Coverage({ area, summary }: CoverageProps) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<MapLibreMap | null>(null);
    const [loadedCells, setLoadedCells] = useState(0);
    const [hovered, setHovered] = useState<CellProperties | null>(null);

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

                instance.addSource('cells', { type: 'geojson', data: cells });
                instance.addSource('mandate', {
                    type: 'geojson',
                    data: { type: 'Feature', geometry: boundary, properties: {} },
                });

                instance.addLayer({
                    id: 'cell-fill',
                    type: 'fill',
                    source: 'cells',
                    paint: { 'fill-color': DENSITY_FILL },
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
                    paint: { 'line-color': '#D0AE63', 'line-width': 1.8 },
                });

                setLoadedCells(cells.features.length);

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

    const stats: Array<[string, string]> = [
        ['Cells', summary.cells.toLocaleString()],
        ['Footprints', summary.footprints.toLocaleString()],
        ['Cells with buildings', summary.cellsWithFootprints.toLocaleString()],
        ['Median per occupied cell', summary.medianPerCell.toLocaleString()],
        ['Busiest cell', summary.busiest.toLocaleString()],
        ['Wards intersecting', summary.wards.toLocaleString()],
    ];

    return (
        <div data-mode="dusk" className="flex h-dvh flex-col bg-surface text-ink">
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
                        Detected buildings per cell
                    </p>
                    <div className="mt-2 flex items-center gap-2">
                        <span className="h-3 flex-1 rounded-[2px] bg-gradient-to-r from-transparent to-gold" />
                    </div>
                    <div className="mt-1 flex justify-between numeric-mono text-label text-faint">
                        <span>0</span>
                        <span>300+</span>
                    </div>
                    <p className="mt-2 text-label text-faint">
                        {loadedCells.toLocaleString()} cells drawn. Outline only means no
                        detected buildings.
                    </p>
                </div>

                {hovered !== null && (
                    <div className="pointer-events-none absolute right-4 bottom-4 rounded-sm border border-rule-strong bg-surface/95 p-3">
                        <p className="numeric-mono text-mono text-ink">{hovered.h3}</p>
                        <p className="mt-1 numeric-mono text-label text-muted">
                            {hovered.footprints.toLocaleString()} detected /{' '}
                            {hovered.captured.toLocaleString()} captured
                        </p>
                        <p className="mt-0.5 text-label text-faint">{hovered.status}</p>
                    </div>
                )}
            </div>
        </div>
    );
}
