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
    const carts = useCarts();
    const cartItems = countItems(carts);
    const firstCart = Object.values(carts)[0];
    const inner = width === 'full' ? 'max-w-[1440px]' : width === 'wide' ? 'max-w-6xl' : 'max-w-4xl';

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <header className="border-b border-rule bg-raised">
                <div className={`mx-auto flex h-[76px] ${inner} items-center justify-between gap-6 px-5`}>
                    <Link href="/directory" className="shrink-0">
                        <GeoVerifyLockup size={40} caption="Nigeria business directory" />
                    </Link>
                    <nav className="hidden items-center gap-7 lg:flex" aria-label="Directory">
                        {(
                            [
                                ['/directory', 'Explore'],
                                ['/directory/sectors', 'Categories'],
                                ['/directory/how-verification-works', 'How verification works'],
                                ['/portal/sign-in', 'For businesses'],
                                ['/invest/sign-in', 'Investors'],
                            ] as const
                        ).map(([href, label]) => (
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
                        {signedIn && (
                            <Link
                                href="/portal/purchases"
                                className="hidden min-h-touch items-center px-2 text-ui font-bold text-ink hover:text-gold sm:inline-flex"
                            >
                                My orders
                            </Link>
                        )}
                        {cartItems > 0 && firstCart !== undefined && (
                            <Link
                                href={`/portal/checkout/${String(firstCart.businessId)}`}
                                className="inline-flex min-h-touch items-center rounded-sm border border-rule-strong px-4 text-ui font-bold text-ink hover:bg-sunken"
                            >
                                Cart · {cartItems}
                            </Link>
                        )}
                        <Link
                            href="/portal/register"
                            className="hidden min-h-touch items-center rounded-sm border border-gold px-4 text-ui font-bold text-gold hover:bg-gold-soft sm:inline-flex"
                        >
                            List your business, free
                        </Link>
                        {!signedIn && (
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

            <footer className="mt-16 border-t border-rule bg-raised">
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
