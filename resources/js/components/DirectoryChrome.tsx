import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { countItems, useCarts } from '@/lib/cart';

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
    // Owner statements and buyers' ratings: null or empty at the reduced depth.
    establishedOn: string | null;
    cell: string | null;
    distanceKm: number | null;
    openNow: boolean | null;
    hours: Record<string, { opens: string; closes: string } | null> | null;
    delivers: boolean | null;
    address: string | null;
    payable: boolean;
    rating: number | null;
    reviewCount: number;
    ordersCompleted: number;
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
    width?: 'wide' | 'narrow' | 'full';
    children: ReactNode;
}) {
    const page = usePage();
    const path = page.url.split('?')[0] ?? '';
    const signedIn = page.props.auth.portal !== null;
    const surfaces = page.props.surfaces;
    const carts = useCarts();
    const cartItems = countItems(carts);
    const firstCart = Object.values(carts)[0];
    const inner = width === 'full' ? 'max-w-[1440px]' : width === 'wide' ? 'max-w-6xl' : 'max-w-4xl';

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <header className="border-b border-rule bg-raised">
                <div className={`mx-auto flex h-[76px] ${inner} items-center justify-between gap-6 px-5`}>
                    <a href="/" aria-label="GeoVerify home" className="shrink-0">
                        <GeoVerifyLockup size={40} />
                    </a>
                    <nav className="hidden items-center gap-7 lg:flex" aria-label="Directory">
                        {(
                            [
                                ['/directory', 'Explore'],
                                ['/directory/sectors', 'Categories'],
                                ['/directory/how-verification-works', 'How verification works'],
                                ['/portal/sign-in', 'For businesses'],
                                ['/invest/sign-in', 'Investors'],
                            ] as const
                        )
                            // A portal that is not open yet has no link.
                            .filter(([href]) => (surfaces.portal || !href.startsWith('/portal')) && (surfaces.invest || !href.startsWith('/invest')))
                            .map(([href, label]) => (
                            <Link
                                key={href}
                                href={href}
                                className={
                                    path === href
                                        ? 'text-body font-semibold text-gold hover:text-gold-dark'
                                        : 'text-body font-semibold text-ink hover:text-gold'
                                }
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                    <div className="flex items-center gap-2.5">
                        {!surfaces.portal && (
                            <Link
                                href="/enumerate"
                                className="inline-flex min-h-touch items-center rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                            >
                                Verify a business
                            </Link>
                        )}
                        {surfaces.portal && signedIn && (
                            <Link
                                href="/portal/purchases"
                                className="hidden min-h-touch items-center px-2 text-ui font-bold text-ink hover:text-gold sm:inline-flex"
                            >
                                My orders
                            </Link>
                        )}
                        {surfaces.portal && cartItems > 0 && firstCart !== undefined && (
                            <Link
                                href={`/portal/checkout/${String(firstCart.businessId)}`}
                                className="inline-flex min-h-touch items-center rounded-sm border border-rule-strong px-4 text-ui font-bold text-ink hover:bg-sunken"
                            >
                                Cart · {cartItems}
                            </Link>
                        )}
                        {surfaces.portal && (
                            <Link
                                href="/portal/register"
                                className="hidden min-h-touch items-center rounded-sm border border-gold px-4 text-ui font-bold text-gold hover:bg-gold-soft sm:inline-flex"
                            >
                                List your business, free
                            </Link>
                        )}
                        {surfaces.portal && !signedIn && (
                            <Link
                                href="/portal/sign-in"
                                className="inline-flex min-h-touch items-center rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                            >
                                Sign in
                            </Link>
                        )}
                    </div>
                </div>
            </header>

            {children}

            {/* Phone: the directory's four tabs, as the phone board. */}
            <nav className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-rule bg-raised pb-[env(safe-area-inset-bottom)] sm:hidden" aria-label="Directory">
                {(
                    [
                        ['/directory', 'Explore', 'M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z'],
                        ...(surfaces.portal
                            ? ([
                                  ['/portal/saved', 'Saved', 'M6 3h12v18l-6-4-6 4z'],
                                  ['/portal/purchases', 'Orders', 'M6 3h9l3 3v15H6zM9 9h6M9 13h6M9 17h4'],
                                  [signedIn ? '/portal' : '/portal/sign-in', 'Account', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 3.6-7 8-7s8 3 8 7'],
                              ] as const)
                            : ([
                                  ['/directory/sectors', 'Categories', 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z'],
                                  ['/directory/how-verification-works', 'How it works', 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 8v.5M12 11v5'],
                                  ['/enumerate', 'Verify', 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5 4.5-5'],
                              ] as const)),
                    ] as const
                ).map(([href, label, icon]) => (
                    <Link
                        key={label}
                        href={href}
                        aria-current={path === href ? 'page' : undefined}
                        className={`flex min-h-[60px] flex-col items-center justify-center gap-1 text-table font-bold ${path === href ? 'text-gold-dark' : 'text-muted'}`}
                    >
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" aria-hidden="true">
                            <path d={icon} />
                        </svg>
                        {label}
                    </Link>
                ))}
            </nav>

            <footer className="mt-16 border-t border-rule bg-raised pb-20 sm:pb-0">
                <div className={`mx-auto ${inner} px-5 py-6 text-table text-faint`}>
                    A register of businesses, not a licence or an endorsement. Nothing here says a
                    business is solvent, lawful or good, only what has been established about it and
                    when.
                </div>
            </footer>
        </div>
    );
}

const SHIELD = 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z';

/** What has actually been established, in words, before any colour. */
export function DepthMark({ depth }: { depth: DirectoryEntry['depth'] }) {
    const look = {
        verified: { label: 'Officer verified', cls: 'bg-gold-soft text-gold-dark', check: true },
        claimed: { label: 'Owner published', cls: 'bg-held-soft text-held-ink', check: false },
        reduced: { label: 'Not verified', cls: 'bg-graphite-soft text-muted', check: false },
    }[depth];

    return (
        <span
            className={`inline-flex items-center gap-1.5 self-start rounded-full px-2.5 py-1 text-label font-bold tracking-normal ${look.cls}`}
        >
            <svg
                aria-hidden="true"
                width="13"
                height="13"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <path d={SHIELD} />
                {look.check && <path d="M8.5 12l2.5 2.5 4.5-5" />}
            </svg>
            {look.label}
        </span>
    );
}

/** One business, as a card. */
export function EntryCard({ entry }: { entry: DirectoryEntry }) {
    return (
        <Link
            href={`/directory/${String(entry.id)}`}
            className="flex h-full flex-col overflow-hidden rounded-card border border-rule bg-raised shadow-card transition-colors hover:border-gold"
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
