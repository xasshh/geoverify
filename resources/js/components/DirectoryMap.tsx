import { Link } from "@inertiajs/react";
import { Map as MapLibreMap, type GeoJSONSource } from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.css";
import "@/lib/maplibre";
import { useEffect, useRef, useState } from "react";
import { cx } from "@/lib/cx";

export interface DirectoryPin {
    id: number;
    name: string;
    sector: string | null;
    ward: string | null;
    cell: string;
    lng: number;
    lat: number;
    state: "verified" | "due" | "published";
}

export interface DirectoryMapData {
    density: GeoJSON.FeatureCollection;
    pins: DirectoryPin[];
    bounds: [[number, number], [number, number]] | null;
    resolution: number;
    floor: number;
}

/** Abuja, for a directory with nothing to frame yet. */
const FALLBACK: [[number, number], [number, number]] = [
    [7.35, 8.95],
    [7.58, 9.15],
];

const STATE_COLOUR = {
    verified: "#0E7C72",
    due: "#B7791F",
    published: "#2F5BEA",
} as const;

/**
 * The directory's map, as the Search & Map board draws it.
 *
 * Density is H3 cells shaded by how many businesses the current search holds
 * there, and a cell under the floor is not drawn. Pins are published
 * businesses only, at the centre of their cell: an unclaimed business has no
 * position it may show, so it has no pin. Where pins crowd, they cluster into
 * the dark count bubbles the guide specifies. Streets come from the register's
 * own OpenStreetMap roads, fetched for whatever box is on screen.
 */
export function DirectoryMap({
    data,
    focus,
    onSelect,
}: {
    data: DirectoryMapData;
    /** A business to fly to and open, from a list card. */
    focus: number | null;
    onSelect?: (id: number | null) => void;
}) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<MapLibreMap | null>(null);
    const [selected, setSelected] = useState<DirectoryPin | null>(null);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        if (container.current === null || map.current !== null) {
            return;
        }

        const instance = new MapLibreMap({
            container: container.current,
            style: {
                version: 8,
                sources: {},
                layers: [
                    {
                        id: "ground",
                        type: "background",
                        paint: { "background-color": "#EEF1EC" },
                    },
                ],
            },
            bounds: data.bounds ?? FALLBACK,
            fitBoundsOptions: { padding: 40 },
            maxPitch: 0,
            attributionControl: false,
        });

        map.current = instance;

        const loadRoads = () => {
            const b = instance.getBounds();
            const url = `/directory/roads.json?w=${String(b.getWest())}&s=${String(b.getSouth())}&e=${String(b.getEast())}&n=${String(b.getNorth())}`;

            void fetch(url, { headers: { Accept: "application/json" } })
                .then(async (r) =>
                    r.ok
                        ? ((await r.json()) as GeoJSON.FeatureCollection)
                        : null,
                )
                .then((roads) => {
                    const source = instance.getSource<GeoJSONSource>("roads");

                    if (roads !== null && source !== undefined) {
                        void source.setData(roads);
                    }
                })
                .catch(() => undefined);
        };

        instance.on("load", () => {
            instance.addSource("roads", {
                type: "geojson",
                data: { type: "FeatureCollection", features: [] },
            });
            instance.addLayer({
                id: "road-casing",
                type: "line",
                source: "roads",
                paint: {
                    "line-color": "#FFFFFF",
                    "line-width": ["case", ["get", "major"], 7, 3.5],
                },
                layout: { "line-cap": "round", "line-join": "round" },
            });

            instance.addSource("density", {
                type: "geojson",
                data: data.density,
            });
            instance.addLayer({
                id: "density-fill",
                type: "fill",
                source: "density",
                paint: {
                    "fill-color": [
                        "interpolate",
                        ["linear"],
                        ["get", "count"],
                        data.floor,
                        "#D5EFEC",
                        10,
                        "#8FD0C9",
                        30,
                        "#3A9C94",
                    ],
                    "fill-opacity": 0.55,
                },
            });
            instance.addLayer({
                id: "density-line",
                type: "line",
                source: "density",
                paint: {
                    "line-color": "#0E7C72",
                    "line-opacity": 0.35,
                    "line-width": 1,
                },
            });

            instance.addSource("pins", {
                type: "geojson",
                data: pinsToGeoJSON(data.pins),
                cluster: true,
                clusterRadius: 38,
                clusterMaxZoom: 14,
            });
            instance.addLayer({
                id: "cluster",
                type: "circle",
                source: "pins",
                filter: ["has", "point_count"],
                paint: {
                    "circle-color": "#0F1A17",
                    "circle-radius": 15,
                    "circle-stroke-color": "#FFFFFF",
                    "circle-stroke-width": 3,
                },
            });
            instance.addLayer({
                id: "pin",
                type: "circle",
                source: "pins",
                filter: ["!", ["has", "point_count"]],
                paint: {
                    "circle-color": [
                        "match",
                        ["get", "state"],
                        "due",
                        STATE_COLOUR.due,
                        "published",
                        STATE_COLOUR.published,
                        STATE_COLOUR.verified,
                    ],
                    "circle-radius": 10,
                    "circle-stroke-color": "#FFFFFF",
                    "circle-stroke-width": 3,
                },
            });

            instance.on("click", "pin", (event) => {
                const id = (
                    event.features?.[0]?.properties as
                        { id?: number } | undefined
                )?.id;
                const pin = data.pins.find((p) => p.id === id) ?? null;
                setSelected(pin);
                onSelect?.(pin?.id ?? null);
            });
            instance.on("click", "cluster", (event) => {
                const feature = event.features?.[0];

                if (feature?.geometry.type === "Point") {
                    instance.easeTo({
                        center: feature.geometry.coordinates as [
                            number,
                            number,
                        ],
                        zoom: instance.getZoom() + 2,
                    });
                }
            });
            for (const layer of ["pin", "cluster"]) {
                instance.on("mouseenter", layer, () => {
                    instance.getCanvas().style.cursor = "pointer";
                });
                instance.on("mouseleave", layer, () => {
                    instance.getCanvas().style.cursor = "";
                });
            }

            instance.on("moveend", loadRoads);
            loadRoads();
            setReady(true);
        });

        return () => {
            instance.remove();
            map.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- the map is built once; new data arrives through the effect below
    }, []);

    // A new search replaces the layers' data rather than rebuilding the map.
    useEffect(() => {
        const instance = map.current;

        if (instance === null || !ready) {
            return;
        }

        void instance
            .getSource<GeoJSONSource>("density")
            ?.setData(data.density);
        void instance
            .getSource<GeoJSONSource>("pins")
            ?.setData(pinsToGeoJSON(data.pins));

        if (data.bounds !== null) {
            instance.fitBounds(data.bounds, { padding: 40, maxZoom: 15 });
        }

        setSelected(null);
    }, [data, ready]);

    useEffect(() => {
        const instance = map.current;
        const pin = data.pins.find((p) => p.id === focus) ?? null;

        if (instance === null || pin === null) {
            return;
        }

        instance.easeTo({
            center: [pin.lng, pin.lat],
            zoom: Math.max(instance.getZoom(), 15),
        });
        setSelected(pin);
    }, [focus, data.pins]);

    return (
        <div className="relative h-full min-h-[520px] overflow-hidden rounded-card border border-rule bg-[#EEF1EC]">
            <div
                ref={container}
                className="h-full w-full"
                role="img"
                aria-label="Map of the businesses in this search"
            />

            <div className="pointer-events-none absolute top-4 left-4 flex gap-2">
                <span className="flex items-center gap-2 rounded-sm bg-raised px-3.5 py-2 text-table font-bold text-ink shadow-card">
                    <svg
                        width="15"
                        height="15"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        aria-hidden="true"
                    >
                        <path d="M12 2.8 20.5 7.5v9L12 21.2 3.5 16.5v-9z" />
                    </svg>
                    H3 cells · res {data.resolution}
                </span>
            </div>

            <div className="absolute top-4 right-4 flex flex-col overflow-hidden rounded-sm bg-raised shadow-card">
                <button
                    type="button"
                    aria-label="Zoom in"
                    onClick={() => map.current?.zoomIn()}
                    className="flex size-11 items-center justify-center border-b border-rule text-body font-bold text-ink hover:bg-sunken"
                >
                    +
                </button>
                <button
                    type="button"
                    aria-label="Zoom out"
                    onClick={() => map.current?.zoomOut()}
                    className="flex size-11 items-center justify-center text-body font-bold text-ink hover:bg-sunken"
                >
                    −
                </button>
            </div>

            {selected !== null && (
                <div className="absolute top-16 right-16 w-[300px] rounded-[18px] bg-raised p-5 shadow-float">
                    <div className="flex items-center justify-between gap-3">
                        <StatePill state={selected.state} />
                        <button
                            type="button"
                            aria-label="Close"
                            onClick={() => {
                                setSelected(null);
                                onSelect?.(null);
                            }}
                            className="text-muted hover:text-ink"
                        >
                            ×
                        </button>
                    </div>
                    <p className="mt-2.5 text-body font-extrabold text-ink">
                        {selected.name}
                    </p>
                    <p className="text-table text-muted">
                        {[selected.sector, selected.ward]
                            .filter(Boolean)
                            .join(" · ")}
                    </p>
                    <p className="mt-1 numeric-mono text-[0.75rem] text-muted">
                        cell {selected.cell}
                    </p>
                    <Link
                        href={`/directory/${String(selected.id)}`}
                        className="mt-4 flex min-h-touch items-center justify-center rounded-sm bg-gold text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                    >
                        View profile
                    </Link>
                </div>
            )}

            <div className="pointer-events-none absolute bottom-4 left-4 rounded-sm bg-raised/95 px-4 py-3 shadow-float">
                <p className="text-table font-bold text-ink">
                    Businesses per cell
                </p>
                <div className="mt-2 flex gap-1">
                    {[
                        "#D5EFEC",
                        "#A9DDD7",
                        "#8FD0C9",
                        "#5DBAB1",
                        "#3A9C94",
                    ].map((c) => (
                        <span
                            key={c}
                            className="h-2.5 w-7 rounded-full"
                            style={{ background: c }}
                        />
                    ))}
                </div>
                <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.75rem] text-muted">
                    <Dot colour={STATE_COLOUR.verified} /> Verified
                    <Dot colour={STATE_COLOUR.due} /> Re-verification due
                    <Dot colour={STATE_COLOUR.published} /> Owner published
                </p>
            </div>
        </div>
    );
}

function Dot({ colour }: { colour: string }) {
    return (
        <span
            className="inline-block size-2.5 rounded-full"
            style={{ background: colour }}
        />
    );
}

export function StatePill({ state }: { state: DirectoryPin["state"] }) {
    const look = {
        verified: ["Verified", "bg-gold-soft text-gold-dark"],
        due: ["Re-verification due", "bg-amber-soft text-amber-ink"],
        published: ["Owner published", "bg-held-soft text-held-ink"],
    }[state];

    return (
        <span
            className={cx(
                "rounded-full px-2.5 py-1 text-table font-bold",
                look[1],
            )}
        >
            {look[0]}
        </span>
    );
}

function pinsToGeoJSON(pins: DirectoryPin[]): GeoJSON.FeatureCollection {
    return {
        type: "FeatureCollection",
        features: pins.map((p) => ({
            type: "Feature",
            geometry: { type: "Point", coordinates: [p.lng, p.lat] },
            properties: { id: p.id, state: p.state },
        })),
    };
}
