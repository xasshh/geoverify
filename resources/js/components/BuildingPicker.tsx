import { cx } from "@/lib/cx";

export interface Building {
    id: number;
    metres: number;
    area_m2: number;
    /** SVG path data in metres, already centred on the person. */
    path: string;
    /** Something is already recorded at this building. */
    occupied: boolean;
}

interface BuildingPickerProps {
    buildings: Building[];
    selected: number | null;
    onSelect: (id: number | null) => void;
}

/** The radius the server searched, plus a margin so nothing touches the edge. */
const EXTENT = 65;

/**
 * Which of these buildings is yours.
 *
 * Deliberately not a map. A tile map is the heaviest thing that could go on the
 * screen where weight matters most: this is a handset on mobile data, and a
 * person who cannot finish this form never becomes a listing. The outlines here
 * come from PostGIS as a few hundred bytes of path data, already projected to
 * metres and already centred on the person, so there is no renderer, no tile
 * requests and nothing to load.
 *
 * It also asks a better question than a map does. A map asks "where are you",
 * which the phone already answered and often got wrong by twenty metres. This
 * asks "which of these is yours", which the phone cannot answer and the person
 * knows with certainty.
 *
 * The list beneath is not a fallback. Shape is a poor way to tell two similar
 * buildings apart and impossible with a screen reader, so distance and size are
 * given as text, and either control selects.
 */
export function BuildingPicker({
    buildings,
    selected,
    onSelect,
}: BuildingPickerProps) {
    return (
        <div className="flex flex-col gap-3">
            <svg
                viewBox={`${String(-EXTENT)} ${String(-EXTENT)} ${String(EXTENT * 2)} ${String(EXTENT * 2)}`}
                className="w-full rounded-card border border-rule bg-sunken"
                role="presentation"
            >
                {buildings.map((building) => (
                    <path
                        key={building.id}
                        d={building.path}
                        className={cx(
                            "cursor-pointer transition-colors",
                            building.id === selected
                                ? "fill-gold/35 stroke-gold"
                                : "fill-graphite/12 stroke-graphite/50 hover:fill-graphite/25",
                        )}
                        strokeWidth={0.7}
                        onClick={() => {
                            onSelect(
                                building.id === selected ? null : building.id,
                            );
                        }}
                    />
                ))}

                {/* Where the phone thinks the person is. Drawn last so it is
                    never hidden under a building, and drawn with its accuracy
                    left off: a ring of uncertainty invites an argument about
                    the position, when the question here is which building. */}
                <circle r={2.2} className="fill-gold" />
                <circle
                    r={4.5}
                    className="fill-none stroke-gold"
                    strokeWidth={0.8}
                />
            </svg>

            <ul className="flex flex-col gap-1.5">
                {buildings.map((building) => (
                    <li key={building.id}>
                        <button
                            type="button"
                            onClick={() => {
                                onSelect(
                                    building.id === selected
                                        ? null
                                        : building.id,
                                );
                            }}
                            className={cx(
                                "flex min-h-touch w-full items-baseline justify-between gap-3 rounded-sm border px-4 py-2 text-left",
                                building.id === selected
                                    ? "border-gold bg-raised"
                                    : "border-rule hover:bg-raised",
                            )}
                        >
                            <span className="text-ui text-ink">
                                {building.metres === 0
                                    ? "The building you are in"
                                    : `${String(building.metres)} m away`}
                                {building.occupied && (
                                    <span className="text-label text-faint">
                                        {" "}
                                        · we already have records here
                                    </span>
                                )}
                            </span>
                            <span className="numeric-mono text-label text-faint">
                                {building.area_m2} m²
                            </span>
                        </button>
                    </li>
                ))}
            </ul>

            <button
                type="button"
                onClick={() => {
                    onSelect(null);
                }}
                className={cx(
                    "min-h-touch rounded-sm border px-4 py-2 text-left text-ui",
                    selected === null
                        ? "border-gold bg-raised text-ink"
                        : "border-rule text-muted hover:bg-raised",
                )}
            >
                None of these. Use the exact spot I am standing on.
            </button>
        </div>
    );
}
