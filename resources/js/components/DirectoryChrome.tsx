import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

export interface DirectoryEntry {
    id: number;
    depth: 'reduced' | 'claimed' | 'verified';
    tradingName: string;
    sector: string | null;
    sectorCode: string | null;
    structureType: string;
    ward: string | null;
    lga: string | null;
    tier: string;
    verified: boolean;
    openingHours: string | null;
    photos: { url: string }[];
}

/**
 * The frame every public directory page wears.
 *
 * Pulled out when the sector pages arrived rather than copied a third time. The
 * band, the rule and the footer are what tell a reader they are still in the
 * register and not on a business's own site, so they had better be the same
 * everywhere.
 */
export function DirectoryChrome({
    width = 'wide',
    children,
}: {
    width?: 'wide' | 'narrow';
    children: ReactNode;
}) {
    const inner = width === 'wide' ? 'max-w-6xl' : 'max-w-4xl';

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <header className="bg-ink text-inverse">
                <div className={`mx-auto flex h-14 ${inner} items-center justify-between gap-4 px-5`}>
                    <Link href="/directory" className="flex items-center gap-2.5">
                        <GeoVerifyMark size={24} ink="light" />
                        <span className="font-display text-display-s">GeoVerify</span>
                        <span aria-hidden="true" className="mx-1 h-4 w-px bg-inverse/25" />
                        <span className="hidden text-label font-semibold tracking-[0.12em] text-inverse/65 uppercase sm:inline">
                            Business directory
                        </span>
                    </Link>
                    <div className="flex items-center gap-5">
                        <Link
                            href="/directory/sectors"
                            className="hidden text-ui text-inverse/75 hover:text-inverse sm:inline"
                        >
                            Sectors
                        </Link>
                        <Link
                            href="/portal/sign-in"
                            className="text-ui text-inverse/75 hover:text-inverse"
                        >
                            Own a business?
                        </Link>
                    </div>
                </div>
            </header>
            <div aria-hidden="true" className="h-[3px] bg-gold" />

            {children}

            <footer className="border-t border-rule">
                <div className={`mx-auto ${inner} px-5 py-6 text-table text-faint`}>
                    A register of businesses, not a licence or an endorsement. Nothing here says a
                    business is solvent, lawful or good, only what has been established about it and
                    when.
                </div>
            </footer>
        </div>
    );
}

/** What has actually been established, in words, before any colour. */
export function DepthMark({ depth }: { depth: DirectoryEntry['depth'] }) {
    if (depth === 'verified') {
        return (
            <span className="flex items-center gap-2 text-label font-semibold tracking-[0.12em] text-green uppercase">
                <span aria-hidden="true" className="size-2.5 rounded-full bg-green" />
                Officer verified
            </span>
        );
    }

    if (depth === 'claimed') {
        return (
            <span className="flex items-center gap-2 text-label font-semibold tracking-[0.12em] text-gold uppercase">
                <span aria-hidden="true" className="size-2.5 bg-gold" />
                Owner published
            </span>
        );
    }

    return (
        <span className="flex items-center gap-2 text-label font-semibold tracking-[0.12em] text-graphite uppercase">
            <span
                aria-hidden="true"
                className="size-2.5 rounded-full border-[1.5px] border-graphite"
            />
            Not verified
        </span>
    );
}

/** One business, as a card. */
export function EntryCard({ entry }: { entry: DirectoryEntry }) {
    return (
        <Link
            href={`/directory/${String(entry.id)}`}
            className="flex h-full flex-col overflow-hidden rounded-sm border border-rule bg-surface transition-colors hover:border-rule-strong"
        >
            {entry.photos.length > 0 && (
                <img
                    src={entry.photos[0]?.url}
                    alt=""
                    className="aspect-3/2 w-full border-b border-rule object-cover"
                />
            )}
            <span className="flex flex-grow flex-col p-5">
                <DepthMark depth={entry.depth} />
                <span className="mt-2.5 font-display text-display-s text-ink">
                    {entry.tradingName}
                </span>
                <span className="mt-1 text-ui text-muted">
                    {entry.sector ?? entry.structureType}
                </span>
                <span className="mt-auto pt-3 text-ui text-faint">
                    {[entry.ward, entry.lga].filter(Boolean).join(', ') || 'Location not resolved'}
                </span>
            </span>
        </Link>
    );
}
