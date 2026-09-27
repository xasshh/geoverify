import { useMemo } from 'react';
import { cx } from '@/lib/cx';
import { floorName, floorShort } from '@/lib/floors';

/**
 * What a building is, drawn from what was counted.
 *
 * Two pieces that answer the same question at different resolutions: the massing
 * says how big the thing is and how many storeys it has, and the section says
 * what is on each of them. Neither invents anything. Where a fact was not
 * recorded the drawing says so in words rather than filling the gap with a
 * plausible shape, because this sits beside a GPS fix and a photograph and has
 * to be read with the same trust as they are.
 */

export interface SectionUnit {
    id: number;
    tradingName: string;
    /** Ground is 0, a basement is negative. Null where nobody recorded one. */
    floor: number | null;
    unitLabel: string | null;
}

export interface Footprint {
    /** The exterior ring, in metres about the building's own centre. */
    ring: ReadonlyArray<readonly [number, number]>;
    widthM: number;
    depthM: number;
    areaM2: number;
}

/**
 * A drawing convention, not a measurement.
 *
 * Storey height is not captured: an officer counts storeys from the street, they
 * do not measure them. 3.2 m is an ordinary Nigerian commercial floor to floor
 * and is used only to give the massing proportions a person can read. No number
 * derived from it is ever shown, because it is not evidence.
 */
const DRAWN_STOREY_M = 3.2;

const ISO_X = Math.cos(Math.PI / 6);
const ISO_Y = Math.sin(Math.PI / 6);

function project(x: number, y: number, z: number): [number, number] {
    return [(x - y) * ISO_X, (x + y) * ISO_Y - z];
}

/**
 * The building as a massing block, on its real outline.
 *
 * Wireframe rather than a render, and deliberately. A shaded solid would be
 * claiming a roof, a colour and a light direction that nobody observed. What is
 * true here is the plan shape and the number of slabs, so those are the only two
 * things drawn.
 */
export function BuildingMassing({
    footprint,
    floors,
    size = 200,
    className,
}: {
    footprint: Footprint | null;
    floors: number | null;
    size?: number;
    className?: string;
}) {
    const drawing = useMemo(() => {
        if (footprint === null || footprint.ring.length < 3) {
            return null;
        }

        // ST_AsGeoJSON closes the ring. Drawing the repeated vertex would put a
        // second corner post on top of the first.
        const first = footprint.ring[0];
        const last = footprint.ring[footprint.ring.length - 1];
        const closed =
            first !== undefined &&
            last !== undefined &&
            first[0] === last[0] &&
            first[1] === last[1];
        const ring = closed ? footprint.ring.slice(0, -1) : [...footprint.ring];

        if (ring.length < 3) {
            return null;
        }

        const storeys = Math.max(1, floors ?? 1);
        const levels = Array.from({ length: storeys + 1 }, (_, i) => i * DRAWN_STOREY_M);

        const projected = levels.map((z) => ring.map(([x, y]) => project(x, y, z)));
        const all = projected.flat();
        const xs = all.map(([x]) => x);
        const ys = all.map(([, y]) => y);
        const minX = Math.min(...xs);
        const maxX = Math.max(...xs);
        const minY = Math.min(...ys);
        const maxY = Math.max(...ys);

        const pad = 8;
        const scale = Math.min(
            (size - pad * 2) / Math.max(maxX - minX, 1e-6),
            (size - pad * 2) / Math.max(maxY - minY, 1e-6),
        );

        const fit = ([x, y]: readonly [number, number]): [number, number] => [
            pad + (x - minX) * scale,
            pad + (y - minY) * scale,
        ];

        const points = (level: ReadonlyArray<[number, number]>): string =>
            level
                .map((point) => {
                    const [x, y] = fit(point);

                    return `${x.toFixed(2)},${y.toFixed(2)}`;
                })
                .join(' ');

        const roofHeight = storeys * DRAWN_STOREY_M;

        return {
            storeys,
            slabs: projected.map(points),
            roof: points(projected[projected.length - 1] ?? []),
            posts: ring.map(([x, y]) => ({
                base: fit(project(x, y, 0)),
                top: fit(project(x, y, roofHeight)),
            })),
        };
    }, [footprint, floors, size]);

    if (drawing === null) {
        return (
            <div
                className={cx(
                    'flex items-center justify-center rounded-sm border border-dashed border-rule px-4 py-6 text-center',
                    className,
                )}
                style={{ minHeight: size / 2 }}
            >
                <p className="text-ui text-muted">
                    No outline was detected here, so there is nothing to draw. Kiosks and
                    containers are recorded from their position alone.
                </p>
            </div>
        );
    }

    return (
        <figure className={cx('flex flex-col items-center gap-2', className)}>
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${String(size)} ${String(size)}`}
                role="img"
                aria-label={`The building's outline, drawn as ${String(drawing.storeys)} ${drawing.storeys === 1 ? 'storey' : 'storeys'}`}
            >
                {/* The corner posts, behind the slabs so the slabs read as the
                    floors rather than as a cage around them. */}
                {drawing.posts.map((post, i) => (
                    <line
                        key={`post-${String(i)}`}
                        x1={post.base[0]}
                        y1={post.base[1]}
                        x2={post.top[0]}
                        y2={post.top[1]}
                        className="stroke-rule-strong"
                        strokeWidth={1}
                    />
                ))}

                {drawing.slabs.map((points, level) => (
                    <polygon
                        key={`slab-${String(level)}`}
                        points={points}
                        className={cx(
                            'fill-none',
                            level === 0 ? 'stroke-rule-strong' : 'stroke-gold',
                        )}
                        strokeWidth={level === 0 ? 1.4 : 1}
                        strokeLinejoin="round"
                        opacity={level === 0 ? 1 : 0.75}
                    />
                ))}

                {/* The roof, the one filled face. It is the only surface the
                    plan shape genuinely describes. */}
                <polygon
                    points={drawing.roof}
                    className="fill-gold stroke-gold"
                    strokeWidth={1.4}
                    strokeLinejoin="round"
                    opacity={0.16}
                />
            </svg>

            {footprint !== null && (
                <figcaption className="numeric-mono text-label text-faint">
                    {footprint.widthM} m by {footprint.depthM} m · {footprint.areaM2} m2
                </figcaption>
            )}
        </figure>
    );
}

/** One business, in the slot it was recorded in. */
function Unit({ unit, tone }: { unit: SectionUnit; tone: 'normal' | 'alert' }) {
    return (
        <li
            className={cx(
                'flex min-w-0 items-baseline gap-2 rounded-sm border px-2 py-1',
                tone === 'alert' ? 'border-alert bg-raised' : 'border-rule bg-raised',
            )}
        >
            {unit.unitLabel !== null && (
                <span className="numeric-mono shrink-0 text-label text-faint">
                    {unit.unitLabel}
                </span>
            )}
            <span className="truncate text-ui text-ink">{unit.tradingName}</span>
        </li>
    );
}

function Storey({
    floor,
    units,
    contradicts = false,
}: {
    floor: number;
    units: SectionUnit[];
    contradicts?: boolean;
}) {
    return (
        <div className="flex items-stretch gap-3 border-t border-rule py-2 first:border-t-0">
            <div className="flex w-24 shrink-0 flex-col justify-center border-r border-rule pr-3">
                <span className="numeric-mono text-mono text-ink">{floorShort(floor)}</span>
                <span className="text-label text-faint">{floorName(floor)}</span>
            </div>

            <div className="min-w-0 flex-1 self-center">
                {units.length === 0 ? (
                    <p className="text-ui text-faint">Nothing recorded on this storey.</p>
                ) : (
                    <ul className="flex flex-wrap gap-1.5">
                        {units.map((unit) => (
                            <Unit
                                key={unit.id}
                                unit={unit}
                                tone={contradicts ? 'alert' : 'normal'}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

/**
 * The building in section: every storey, and what was found on it.
 *
 * Read top down, the way you would look up at the building from the street.
 * Three groups sit below it and each is a different kind of not knowing, which
 * is why they are not merged: a business whose storey was never recorded, a unit
 * the officer counted but did not get into, and a business recorded above the
 * roofline, which is a contradiction rather than a gap.
 */
export function BuildingSection({
    floors,
    unitCount,
    units,
    className,
}: {
    floors: number | null;
    unitCount: number | null;
    units: readonly SectionUnit[];
    className?: string;
}) {
    const storeys = floors ?? 0;

    const placed = units.filter((u) => u.floor !== null && u.floor >= 0 && u.floor < storeys);
    const unplaced = units.filter((u) => u.floor === null);
    const above = units.filter((u) => u.floor !== null && u.floor >= storeys);
    const below = units.filter((u) => u.floor !== null && u.floor < 0);

    // Only the basements something was actually found in. `floors` counts
    // upwards from the ground and says nothing about what is underneath, so
    // drawing an empty basement would be inventing a storey.
    const basements = [...new Set(below.map((u) => u.floor as number))].sort((a, b) => b - a);

    /*
     * Counted but not yet recorded. Building wide on purpose: the officer
     * counted shutters from the street, so we know how many units exist and we
     * do not know which storey the missing ones are on. Spreading them evenly
     * would look like knowledge.
     */
    const outstanding = unitCount === null ? 0 : Math.max(0, unitCount - units.length);

    if (floors === null) {
        return (
            <div className={cx('flex flex-col gap-2', className)}>
                <p className="rounded-sm bg-amber-soft px-3 py-2 text-ui text-muted">
                    No storey count was recorded for this building, so it cannot be drawn in
                    section. The businesses below are everything found here.
                </p>
                <ul className="flex flex-wrap gap-1.5">
                    {units.map((unit) => (
                        <Unit key={unit.id} unit={unit} tone="normal" />
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <div className={cx('flex flex-col', className)}>
            <div className="rounded-card border border-rule px-3 bg-raised">
                {Array.from({ length: storeys }, (_, i) => storeys - 1 - i).map((floor) => (
                    <Storey
                        key={floor}
                        floor={floor}
                        units={placed.filter((u) => u.floor === floor)}
                    />
                ))}

                {basements.map((floor) => (
                    <Storey
                        key={floor}
                        floor={floor}
                        units={below.filter((u) => u.floor === floor)}
                    />
                ))}
            </div>

            <div className="mt-2 flex flex-col gap-1.5">
                {above.length > 0 && (
                    <div className="rounded-sm bg-alert-soft px-3 py-2">
                        <p className="text-ui text-alert">
                            {above.length === 1 ? 'One business is' : `${String(above.length)} businesses are`}{' '}
                            recorded above the {String(storeys)} storeys counted here. Either the
                            storeys or the floor is wrong.
                        </p>
                        <ul className="mt-1.5 flex flex-wrap gap-1.5">
                            {above.map((unit) => (
                                <Unit key={unit.id} unit={unit} tone="alert" />
                            ))}
                        </ul>
                    </div>
                )}

                {unplaced.length > 0 && (
                    <div className="rounded-sm bg-sunken px-3 py-2">
                        <p className="text-ui text-muted">
                            No storey was recorded for{' '}
                            {unplaced.length === 1 ? 'this business' : 'these businesses'}.
                        </p>
                        <ul className="mt-1.5 flex flex-wrap gap-1.5">
                            {unplaced.map((unit) => (
                                <Unit key={unit.id} unit={unit} tone="normal" />
                            ))}
                        </ul>
                    </div>
                )}

                {outstanding > 0 && (
                    <p className="numeric-mono text-label text-faint">
                        {outstanding} of {String(unitCount ?? 0)} units counted here have no
                        business recorded yet. Which storey they are on is not known.
                    </p>
                )}
            </div>
        </div>
    );
}
