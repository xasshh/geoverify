import { Head, router } from '@inertiajs/react';
import { ConsoleShell } from '@/components/ConsoleShell';
import { lastSeenLabel, useLiveMap, type Live } from '@/components/LiveMap';
import { PresenceMark } from '@/components/PresenceMark';
import { cx } from '@/lib/cx';

interface LiveProps {
    live: Live;
    bounds: [number, number, number, number];
    areas: Array<{ id: number; name: string }>;
    filters: { area: number | null };
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: string }) {
    return (
        <div className="flex flex-col gap-0.5 border-l-2 border-rule-strong pl-3">
            <span className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                {label}
            </span>
            <span className={cx('numeric-mono text-display-s', tone ?? 'text-ink')}>{value}</span>
        </div>
    );
}

/**
 * Live operations.
 *
 * A supervisor with eight officers out cannot ring each of them, and what they
 * need is not "is everyone busy" but "is anyone stuck, lost, or somewhere they
 * should not be". So the officers who have gone quiet sort to the top, and the
 * day's contested captures are on the page rather than a click away.
 *
 * The traces are drawn on the map at full length and repeated as Presence Marks
 * in the list, because the mark carries density where the map carries route: a
 * fabricated day is a straight line in the column before anyone reads a number.
 */
export default function Live({ live, bounds, areas, filters }: LiveProps) {
    const { container, map, data, refreshedAt, selected, setSelected } = useLiveMap({ live, bounds, filters });

    const quiet = data.officers.filter((o) => !o.active).length;

    return (
        <ConsoleShell current="live">
            <Head title="Live operations" />

            <div className="mx-auto max-w-[1400px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b border-rule pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                            Supervision
                        </p>
                        <h1 className="font-display text-display-m text-ink">Live operations</h1>
                    </div>
                    <div className="flex items-center gap-4">
                        <select
                            value={filters.area ?? ''}
                            onChange={(event) => {
                                router.get(
                                    '/console/live',
                                    event.target.value === '' ? {} : { area: event.target.value },
                                    { preserveState: false, replace: true },
                                );
                            }}
                            className="rounded-sm border border-rule-strong bg-raised px-3 py-1.5 text-ui text-ink"
                        >
                            <option value="">Every mandate</option>
                            {areas.map((area) => (
                                <option key={area.id} value={area.id}>
                                    {area.name}
                                </option>
                            ))}
                        </select>
                        <p className="numeric-mono text-mono text-faint">
                            checked {refreshedAt.toLocaleTimeString()}
                        </p>
                    </div>
                </header>

                <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Captured today" value={String(data.day.captures)} />
                    <Stat label="Officers capturing" value={String(data.day.officers)} />
                    <Stat
                        label="Mean confidence"
                        value={data.day.meanConfidence === null ? 'none' : String(data.day.meanConfidence)}
                    />
                    <Stat
                        label="Contested today"
                        value={String(data.day.contested)}
                        tone={data.day.contested > 0 ? 'text-alert' : 'text-ink'}
                    />
                </div>

                <div className="mt-6 grid gap-5 lg:grid-cols-[1fr_380px]">
                    <div className="relative h-[560px] overflow-hidden rounded-card border border-rule bg-raised">
                        <div ref={container} className="h-full w-full" data-testid="live-map" />
                        {data.officers.length === 0 && (
                            <p className="pointer-events-none absolute inset-0 flex items-center justify-center text-ui text-faint">
                                Nobody has opened a session in the last eighteen hours.
                            </p>
                        )}
                    </div>

                    <aside className="flex max-h-[560px] flex-col gap-2 overflow-y-auto">
                        {quiet > 0 && (
                            <p className="rounded-sm bg-amber-soft px-3 py-2 text-ui text-muted">
                                {quiet === 1
                                    ? '1 officer has gone quiet.'
                                    : `${String(quiet)} officers have gone quiet.`}
                            </p>
                        )}

                        {data.officers.map((officer) => (
                            <button
                                key={officer.officerId}
                                type="button"
                                onClick={() => {
                                    setSelected(officer.officerId);

                                    if (officer.longitude !== null && officer.latitude !== null) {
                                        map.current?.easeTo({
                                            center: [officer.longitude, officer.latitude],
                                            zoom: 15,
                                            duration: 600,
                                        });
                                    }
                                }}
                                className={cx(
                                    'flex gap-3 rounded-sm border p-3 text-left',
                                    selected === officer.officerId
                                        ? 'border-gold bg-raised'
                                        : 'border-rule hover:border-rule-strong',
                                )}
                            >
                                <PresenceMark
                                    points={officer.trace}
                                    size={40}
                                    tone={officer.isMock ? 'alert' : officer.active ? 'gold' : 'green'}
                                    showCapturePoint={false}
                                    label={`${officer.officer}'s trace today, ${String(officer.trace.length)} points`}
                                />

                                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span className="flex items-baseline justify-between gap-2">
                                        <span className="truncate text-ui font-semibold text-ink">
                                            {officer.officer}
                                        </span>
                                        <span
                                            className={cx(
                                                'numeric-mono shrink-0 text-label',
                                                officer.active ? 'text-green' : 'text-amber-ink',
                                            )}
                                        >
                                            {lastSeenLabel(officer)}
                                        </span>
                                    </span>

                                    <span className="numeric-mono text-label text-faint">
                                        {officer.captures} captured · {(officer.distanceM / 1000).toFixed(1)} km
                                        {officer.meanConfidence !== null &&
                                            ` · conf ${String(officer.meanConfidence)}`}
                                    </span>

                                    {officer.h3 !== null && (
                                        <span className="numeric-mono text-label text-faint">
                                            {officer.h3}
                                        </span>
                                    )}

                                    {officer.isMock && (
                                        <span className="text-label text-alert">
                                            A mock location provider is running on this device.
                                        </span>
                                    )}
                                </span>
                            </button>
                        ))}
                    </aside>
                </div>
            </div>
        </ConsoleShell>
    );
}
