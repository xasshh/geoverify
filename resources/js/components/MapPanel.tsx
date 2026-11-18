import { useEffect, useState } from 'react';
import type { Map as MapLibreMap } from 'maplibre-gl';
import { cx } from '@/lib/cx';
import type { Imagery, MapView } from '@/lib/geomap';
import { setMapView } from '@/lib/geomap';

/**
 * The panel every staff map shares: street or satellite, and which of the
 * page's own layers are drawn.
 *
 * A page names its layer groups (label and the layer ids behind it); this
 * keeps them in the state the person chose, including layers a page adds
 * after a fetch lands, because a layer hidden before it existed should not
 * appear the moment it does.
 */

export interface LayerGroup {
    label: string;
    ids: string[];
    /** Shown on first load. Defaults to true. */
    visible?: boolean;
}

interface Props {
    map: MapLibreMap | null;
    imagery?: Imagery[];
    layers?: LayerGroup[];
    /** Placement inside the map's (relative) wrapper. */
    className?: string;
    /** Told when the view changes, for a page that restyles its own layers over imagery. */
    onView?: (view: MapView) => void;
}

export function MapPanel({ map, imagery = [], layers = [], className = 'bottom-9 left-2', onView }: Props) {
    const [view, setView] = useState<MapView>('street');
    const [opacity, setOpacity] = useState(1);
    const [shown, setShown] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(layers.map((group) => [group.label, group.visible ?? true])),
    );
    const [open, setOpen] = useState(true);

    useEffect(() => {
        if (map === null) {
            return;
        }

        // Only what differs is written, so the style event this answers is not
        // fired again by the answer.
        const apply = () => {
            if (!map.isStyleLoaded()) {
                return;
            }

            for (const group of layers) {
                const want = (shown[group.label] ?? true) ? 'visible' : 'none';

                for (const id of group.ids) {
                    if (map.getLayer(id) !== undefined && (map.getLayoutProperty(id, 'visibility') ?? 'visible') !== want) {
                        map.setLayoutProperty(id, 'visibility', want);
                    }
                }
            }

            const imageryShown = view === 'satellite' ? 'visible' : 'none';
            const first = map.getStyle().layers.find((layer) => layer.id.startsWith('gv-imagery-'));

            if (
                first !== undefined &&
                ((map.getLayoutProperty(first.id, 'visibility') ?? 'visible') !== imageryShown ||
                    map.getPaintProperty(first.id, 'raster-opacity') !== opacity)
            ) {
                setMapView(map, view, opacity);
            }
        };

        apply();
        map.on('styledata', apply);
        map.on('load', apply);

        return () => {
            map.off('styledata', apply);
            map.off('load', apply);
        };
    }, [map, layers, shown, view, opacity]);

    if (imagery.length === 0 && layers.length === 0) {
        return null;
    }

    const captured = imagery.map((i) => i.captured).filter((c): c is string => c !== null);
    const licence = imagery.find((i) => i.licence !== null)?.licence ?? null;

    return (
        <div
            className={cx(
                'absolute z-10 w-56 rounded-card border border-rule bg-raised/95 p-2.5 text-ink shadow-float',
                className,
            )}
        >
            <button
                type="button"
                onClick={() => {
                    setOpen((was) => !was);
                }}
                aria-expanded={open}
                className="flex w-full items-center justify-between text-label font-semibold tracking-[0.05em] text-muted uppercase"
            >
                Map
                <span aria-hidden>{open ? '−' : '+'}</span>
            </button>

            {open && (
                <div className="mt-2 flex flex-col gap-2.5">
                    {imagery.length > 0 && (
                        <div className="flex flex-col gap-1.5">
                            <div role="radiogroup" aria-label="Map view" className="grid grid-cols-2 gap-1 rounded-sm bg-sunken p-0.5">
                                {(['street', 'satellite'] as const).map((kind) => (
                                    <button
                                        key={kind}
                                        type="button"
                                        role="radio"
                                        aria-checked={view === kind}
                                        onClick={() => {
                                            setView(kind);
                                            onView?.(kind);
                                        }}
                                        className={cx(
                                            'rounded-sm px-2 py-1 text-label capitalize',
                                            view === kind ? 'bg-raised font-bold text-ink shadow-card' : 'text-muted',
                                        )}
                                    >
                                        {kind}
                                    </button>
                                ))}
                            </div>

                            {view === 'satellite' && (
                                <>
                                    <input
                                        type="range"
                                        min={20}
                                        max={100}
                                        step={10}
                                        value={Math.round(opacity * 100)}
                                        onChange={(e) => {
                                            setOpacity(Number(e.target.value) / 100);
                                        }}
                                        aria-label="Satellite opacity"
                                        className="w-full accent-gold"
                                    />
                                    <p className="text-label text-faint">
                                        {captured.length > 0 ? `Imagery ${captured.join(', ')}. ` : ''}
                                        Shown over the mandates it was built for.
                                        {licence !== null && ` ${licence}`}
                                    </p>
                                </>
                            )}
                        </div>
                    )}

                    {layers.length > 0 && (
                        <ul className="flex flex-col gap-1">
                            {layers.map((group) => (
                                <li key={group.label}>
                                    <label className="flex items-center gap-2 text-label text-ink">
                                        <input
                                            type="checkbox"
                                            checked={shown[group.label] ?? true}
                                            onChange={(e) => {
                                                setShown((was) => ({ ...was, [group.label]: e.target.checked }));
                                            }}
                                            className="size-4 accent-gold"
                                        />
                                        {group.label}
                                    </label>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
