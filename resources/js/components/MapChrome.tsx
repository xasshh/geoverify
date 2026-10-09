import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cx } from '@/lib/cx';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

interface CoverageBarProps {
    captured: number;
    detected: number;
}

/**
 * The denominator, permanently on screen.
 *
 * Completion is measured against detected building footprints, not against what
 * the officer claims. Without this the number means nothing.
 */
export function CoverageBar({ captured, detected }: CoverageBarProps) {
    const pct = detected === 0 ? 0 : Math.min(100, (captured / detected) * 100);

    return (
        <div className="px-4 py-2">
            <div className="mb-1.5 flex items-baseline justify-between numeric-mono text-ui">
                <span className="text-ink">
                    {captured} / {detected}
                </span>
                <span className="text-muted">{pct.toFixed(1)}%</span>
            </div>
            <div
                className="h-1.5 w-full overflow-hidden rounded-full bg-sunken"
                role="progressbar"
                aria-valuenow={Math.round(pct)}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`Coverage: ${String(captured)} of ${String(detected)} detected structures captured`}
            >
                <div
                    className="h-full rounded-full bg-gold transition-[width] duration-500 motion-reduce:transition-none"
                    style={{ width: `${String(pct)}%` }}
                />
            </div>
        </div>
    );
}

interface MapChromeProps {
    /** What the officer is working: the mandate's name, never the raw cell index. */
    title: string;
    /** Where the back button goes. */
    backHref: string;
    openFlags?: number;
    sync: ReactNode;
    coverage: ReactNode;
    /** The map itself. Chrome sits over it and gets out of the way. */
    children: ReactNode;
    actions: ReactNode;
}

/**
 * The field capture screen's frame.
 *
 * The map is the interface, but it is a bounded work list rather than an explorer:
 * the officer is working one assigned cell, the cell identity and open flags sit at
 * the top, the denominator and the actions sit in the lower third where a thumb
 * reaches them.
 */
export function MapChrome({
    title,
    backHref,
    openFlags = 0,
    sync,
    coverage,
    children,
    actions,
}: MapChromeProps) {
    return (
        <div className="flex h-full flex-col overflow-hidden rounded-card border border-rule bg-raised">
            <header className="shrink-0 border-b border-rule px-4 py-2.5">
                <div className="flex items-center justify-between gap-3">
                    <span className="flex min-w-0 items-center gap-2">
                        <Link
                            href={backHref}
                            aria-label="Back"
                            className="-ml-2 flex size-11 shrink-0 items-center justify-center rounded-full text-ink hover:bg-sunken"
                        >
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden="true">
                                <path d="M15 18l-6-6 6-6" />
                            </svg>
                        </Link>
                        <GeoVerifyMark size={20} />
                        <span className="truncate text-ui font-bold text-ink">{title}</span>
                    </span>
                    {openFlags > 0 && (
                        <span className="flex items-center gap-1.5 text-ui font-semibold text-amber-ink">
                            <svg width="10" height="9" viewBox="0 0 11 10" aria-hidden="true">
                                <path d="M5.5 0 11 10H0z" fill="currentColor" />
                            </svg>
                            {openFlags} open
                        </span>
                    )}
                </div>
                <div className="mt-1.5">{sync}</div>
            </header>

            {/* The map. Everything above and below is chrome over it. */}
            <div className="relative min-h-0 flex-1 bg-sunken">{children}</div>

            <div className="shrink-0 border-t border-rule">{coverage}</div>

            <div className="shrink-0 border-t border-rule px-4 pt-3 pb-4">{actions}</div>
        </div>
    );
}

interface FootprintLegendProps {
    className?: string;
}

/**
 * Footprints are a work list, not a record: each carries a visited state, so the
 * officer's remaining work is visible rather than inferred.
 */
export function FootprintLegend({ className }: FootprintLegendProps) {
    const items = [
        { label: 'Unvisited', className: 'border-graphite bg-transparent' },
        { label: 'Captured', className: 'border-green bg-green' },
        { label: 'Not a building', className: 'border-graphite bg-graphite/30' },
    ];

    return (
        <ul className={cx('flex flex-wrap gap-x-4 gap-y-1.5 text-label text-muted', className)}>
            {items.map((item) => (
                <li key={item.label} className="flex items-center gap-1.5">
                    <span className={cx('size-2.5 rounded-[1px] border', item.className)} />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}
