import type { ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { GeoVerifyLockup, GeoVerifyMark } from '@/components/GeoVerifyMark';

type NavKey =
    | 'overview'
    | 'explore'
    | 'opportunities'
    | 'watchlist'
    | 'rooms'
    | 'reports'
    | 'settings';

/** 24px outline marks, drawn in currentColor. */
const NAV: { key: NavKey; label: string; href: string; icon: string; gated: boolean }[] = [
    { key: 'overview', label: 'Overview', href: '/invest', icon: 'M3 10.5 12 3l9 7.5M5.5 9v11h13V9', gated: false },
    {
        key: 'explore',
        label: 'Explore map',
        href: '/invest/explore',
        icon: 'M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
        gated: false,
    },
    {
        key: 'opportunities',
        label: 'Opportunities',
        href: '/invest/opportunities',
        icon: 'M3.5 7.5h17v12h-17zM8.5 7.5V5h7v2.5M3.5 12.5h17',
        gated: true,
    },
    { key: 'watchlist', label: 'Watchlist', href: '/invest/watchlist', icon: 'M6.5 3.5h11v17l-5.5-4-5.5 4z', gated: true },
    {
        key: 'rooms',
        label: 'Data rooms',
        href: '/invest/data-rooms',
        icon: 'M5.5 10.5h13v10h-13zM8.5 10.5V7a3.5 3.5 0 0 1 7 0v3.5',
        gated: true,
    },
    {
        key: 'reports',
        label: 'Reports',
        href: '/invest/reports',
        icon: 'M6 3h8.5L19 7.5V21H6zM14 3v5h5M9 12.5h6M9 16h6',
        gated: true,
    },
    {
        key: 'settings',
        label: 'Settings',
        href: '/invest/settings',
        icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM12 2.5v2.5M12 19v2.5M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M2.5 12H5M19 12h2.5M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8',
        gated: false,
    },
];

function Icon({ path, size = 20 }: { path: string; size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className="shrink-0"
        >
            <path d={path} />
        </svg>
    );
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
}

/**
 * The Global Investor and Discovery Portal's frame.
 *
 * The guide's shell, the same as the business portal and the console: a 280px
 * sidebar, a white header band with the page title and its controls, and the
 * canvas. The foot of the sidebar says whether the organisation has passed KYC,
 * in the dark card the mockup gives it, because that single fact decides what
 * half of this portal will show.
 */
export function InvestorShell({
    current,
    title,
    subtitle,
    crumbs,
    actions,
    children,
}: {
    current: NavKey | null;
    title: ReactNode;
    subtitle?: ReactNode | undefined;
    /** A breadcrumb line above the title, for pages below a list. */
    crumbs?: ReactNode | undefined;
    actions?: ReactNode | undefined;
    children: ReactNode;
}) {
    const page = usePage();
    const investor = page.props.auth.investor;
    const flash = page.props.flash.status;
    const verified = investor?.verified === true;

    const signOut = () => {
        router.post('/invest/sign-out');
    };

    const navRow = (item: (typeof NAV)[number], dense: boolean) => {
        const active = item.key === current;
        const locked = item.gated && !verified;

        return (
            <Link
                key={item.key}
                href={item.href}
                aria-current={active ? 'page' : undefined}
                className={cx(
                    'flex shrink-0 items-center gap-3 rounded-sm px-3.5 text-ui transition-colors',
                    dense ? 'min-h-touch' : 'min-h-[50px]',
                    active
                        ? 'bg-gold-soft font-bold text-gold-dark'
                        : 'font-semibold text-ink hover:bg-sunken',
                    locked && !active && 'text-faint',
                )}
            >
                <Icon path={item.icon} />
                {item.label}
                {locked && (
                    <span className="sr-only">, opens once your organisation is verified</span>
                )}
            </Link>
        );
    };

    return (
        <div
            data-mode="daylight"
            className="min-h-dvh bg-surface text-ink lg:grid lg:grid-cols-[280px_minmax(0,1fr)]"
        >
            <aside className="hidden border-r border-rule bg-raised lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col">
                <a href="/" aria-label="GeoVerify home" className="flex items-center px-7 pt-7 pb-6">
                    <GeoVerifyLockup caption="Investor & discovery" />
                </a>

                <nav className="flex flex-col gap-1 px-5" aria-label="Investor portal">
                    {NAV.map((item) => navRow(item, false))}
                </nav>

                <div className="mt-auto flex flex-col gap-3 px-5 pb-6">
                    <div className="rounded-sm bg-ink px-4 py-4 text-inverse">
                        <p className="text-ui font-extrabold">
                            {verified ? 'Verified investor' : 'Verification pending'}
                        </p>
                        <p className="mt-1.5 text-[0.8125rem] leading-snug text-inverse/75">
                            {verified
                                ? 'KYC complete. You can request data-room access from verified businesses.'
                                : 'We are checking your organisation. The overview and the map are open meanwhile.'}
                        </p>
                    </div>

                    {investor !== null && (
                        <div className="flex items-center gap-3 px-2 py-2">
                            <span
                                aria-hidden="true"
                                className="flex size-11 shrink-0 items-center justify-center rounded-full bg-held-soft text-ui font-extrabold text-held-ink"
                            >
                                {initials(investor.name)}
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span className="truncate text-ui font-bold text-ink">
                                    {investor.name}
                                </span>
                                <span className="truncate text-[0.75rem] text-faint">
                                    {[investor.organisation, investor.title].filter(Boolean).join(' · ')}
                                </span>
                                <button
                                    type="button"
                                    onClick={signOut}
                                    className="self-start text-[0.75rem] font-semibold text-faint hover:text-ink"
                                >
                                    Sign out
                                </button>
                            </span>
                        </div>
                    )}
                </div>
            </aside>

            <div className="border-b border-rule bg-raised lg:hidden">
                <div className="flex items-center gap-2.5 px-4 py-3">
                    <a href="/" aria-label="GeoVerify home">
                        <GeoVerifyMark size={30} />
                    </a>
                    <span className="font-wordmark text-[1.125rem] font-bold text-logo">
                        GeoVerify
                    </span>
                    <button
                        type="button"
                        onClick={signOut}
                        className="ml-auto min-h-touch rounded-sm border border-rule-strong bg-raised px-3 text-ui font-bold text-ink"
                    >
                        Sign out
                    </button>
                </div>
                <nav className="flex gap-1 overflow-x-auto px-3 pb-3" aria-label="Investor portal">
                    {NAV.map((item) => navRow(item, true))}
                </nav>
            </div>

            <div className="min-w-0">
                <header className="border-b border-rule bg-raised">
                    <div className="flex min-h-[104px] flex-wrap items-center justify-between gap-4 px-5 py-5 lg:px-10">
                        <div className="min-w-0">
                            {crumbs !== undefined && (
                                <p className="mb-1 text-table font-semibold text-muted">{crumbs}</p>
                            )}
                            <h1 className="font-display text-display-l text-ink">{title}</h1>
                            {subtitle !== undefined && (
                                <p className="mt-1 text-ui text-muted">{subtitle}</p>
                            )}
                        </div>
                        {actions !== undefined && (
                            <div className="flex flex-wrap items-center gap-2.5">{actions}</div>
                        )}
                    </div>
                </header>

                <main className="max-w-[1320px] px-5 pt-8 pb-24 lg:px-10">
                    {flash !== null && (
                        <p
                            role="status"
                            className="mb-6 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green"
                        >
                            {flash}
                        </p>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}

export { Icon as InvestorIcon };
