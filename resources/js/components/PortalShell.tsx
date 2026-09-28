import type { ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { Initials } from '@/components/ConsoleShell';
import { GeoVerifyLockup, GeoVerifyMark } from '@/components/GeoVerifyMark';

interface PortalShellProps {
    /** Shown in the sidebar. Absent before anyone has signed in. */
    accountName?: string | null;
    /** A short page title, in the header band. */
    kicker?: string | undefined;
    title?: string | undefined;
    /** One line under the title: whose page this is, and where. */
    subtitle?: ReactNode | undefined;
    /** Buttons at the right of the header band. */
    actions?: ReactNode | undefined;
    children: ReactNode;
    /** Narrow for a form, wide once there is a register to read. */
    width?: 'form' | 'page';
}

type NavKey =
    | 'home'
    | 'listings'
    | 'orders'
    | 'wallet'
    | 'inspections'
    | 'hours'
    | 'saved'
    | 'purchases'
    | 'verification'
    | 'profile'
    | 'investors'
    | 'team'
    | 'claim'
    | 'add'
    | 'settings';

/** 24px outline marks, drawn in currentColor. */
const ICONS: Record<NavKey, string> = {
    home: 'M3 10.5 12 3l9 7.5M5.5 9v11h13V9',
    listings: 'M12 2.8 20.5 7.5v9L12 21.2 3.5 16.5v-9zM3.5 7.5 12 12l8.5-4.5M12 12v9.2',
    orders: 'M6 3h9l3 3v15H6zM9 9h6M9 13h6M9 17h4',
    hours: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
    saved: 'M6 3h12v18l-6-4-6 4z',
    inspections: 'M4 7h11v11H4zM17 14a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.2 13.2l2.3 2.3',
    wallet: 'M3.5 7.5h15a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-15zM3.5 7.5V6a2 2 0 0 1 2-2h11M16 14h2',
    purchases: 'M4 5h2l2 11h10l2-8H7.5M9.5 20a1 1 0 1 0 0-.1M17 20a1 1 0 1 0 0-.1',
    verification: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5 4.5-5',
    profile: 'M4 9.5 5.5 4h13L20 9.5M4 9.5h16M4 9.5v10.5h16V9.5M9.5 20v-5h5v5',
    team: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM3 20c0-3.5 2.7-6 6-6s6 2.5 6 6M16 4.5a3.5 3.5 0 0 1 0 6.5M18 14.5c2 .6 3 2.6 3 5.5',
    claim: 'M10.5 17.5a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20.5 20.5l-5-5M8 10.5l2 2 3.5-3.5',
    add: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 8v8M8 12h8',
    investors: 'M3.5 20.5h17M6 20.5v-7M10 20.5V9M14 20.5v-5M18 20.5V5',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM12 2.5v2.5M12 19v2.5M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M2.5 12H5M19 12h2.5M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8',
};

function Icon({ path }: { path: string }) {
    return (
        <svg
            width="20"
            height="20"
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

/**
 * The portal's frame.
 *
 * Signed in, it is the guide's shell: a 280px sidebar with the business's own
 * surfaces, a white header band carrying the page title and its actions, and
 * the canvas under it. The same shell as the console, so a business owner and
 * a supervisor learn one layout, but with a business's destinations rather than
 * a register room's queues.
 *
 * Signed out (sign in, the code, a new account) it is one centred card under
 * the wordmark: nobody needs navigation to type a phone number.
 *
 * Laid out at 360px first, because that is the device most of this audience is
 * holding. Below lg the sidebar becomes a top bar with the destinations in a
 * scrolling strip, so nothing hides behind a menu button.
 */
export function PortalShell({
    accountName = null,
    kicker,
    title,
    subtitle,
    actions,
    children,
    width = 'form',
}: PortalShellProps) {
    const page = usePage();
    const flash = page.props.flash.status;
    const business = page.props.auth.portal?.business ?? null;
    const path = page.url.split('?')[0] ?? '';

    const signOut = () => {
        router.post('/portal/sign-out');
    };

    const notice = flash !== null && (
        <p
            role="status"
            className="mb-6 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green"
        >
            {flash}
        </p>
    );

    const heading = (kicker !== undefined || title !== undefined) && (
        <div className="min-w-0">
            {kicker !== undefined && (
                <p className="text-label font-extrabold tracking-[0.05em] text-gold uppercase">
                    {kicker}
                </p>
            )}
            {title !== undefined && (
                <h1 className="mt-1 font-display text-display-l text-ink">{title}</h1>
            )}
            {subtitle !== undefined && <p className="mt-1 text-ui text-muted">{subtitle}</p>}
        </div>
    );

    if (accountName === null) {
        return (
            <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
                <header className="border-b border-rule bg-raised">
                    <div className="mx-auto flex h-[72px] max-w-5xl items-center px-5">
                        <Link href="/portal/sign-in">
                            <GeoVerifyLockup size={36} caption="Business portal" />
                        </Link>
                    </div>
                </header>
                <main className="mx-auto max-w-xl px-5 pt-10 pb-24">
                    {notice}
                    <div className="rounded-card border border-rule bg-raised px-6 py-7 shadow-card sm:px-8">
                        {heading !== false && <div className="mb-6">{heading}</div>}
                        {children}
                    </div>
                </main>
            </div>
        );
    }

    // The mockup's Merchant Hub nav once a business is attached; before that,
    // the two ways to get one. My orders is the buyer's
    // side, which anybody signed in has, business or not.
    const base = business === null ? null : `/portal/businesses/${String(business.id)}`;
    const nav: { key: NavKey; label: string; href: string; count?: number }[] =
        base === null || business === null
            ? [
                  { key: 'home', label: 'Home', href: '/portal' },
                  { key: 'purchases', label: 'My orders', href: '/portal/purchases' },
                  { key: 'saved', label: 'Saved', href: '/portal/saved' },
                  { key: 'claim', label: 'Claim a business', href: '/portal/claim' },
                  { key: 'add', label: 'Add a business', href: '/portal/register-business' },
                  { key: 'settings', label: 'Settings', href: '/portal/settings' },
              ]
            : [
                  { key: 'home', label: 'Home', href: '/portal' },
                  { key: 'listings', label: 'Listings', href: `${base}/listings` },
                  { key: 'orders', label: 'Orders', href: '/portal/orders', count: business.openOrders },
                  { key: 'inspections', label: 'Inspections & visits', href: '/portal/inspections' },
                  { key: 'wallet', label: 'Wallet', href: '/portal/wallet' },
                  { key: 'verification', label: 'Verification', href: `${base}/verification` },
                  { key: 'profile', label: 'Business profile', href: base },
                  { key: 'hours', label: 'Hours & delivery', href: `${base}/profile` },
                  { key: 'investors', label: 'Investors', href: `${base}/investors` },
                  { key: 'team', label: 'Team & roles', href: '/portal/team' },
                  { key: 'purchases', label: 'My orders', href: '/portal/purchases' },
                  { key: 'saved', label: 'Saved', href: '/portal/saved' },
                  { key: 'settings', label: 'Settings', href: '/portal/settings' },
              ];

    // The longest href the path sits under is the current one, so the
    // listing does not light up beside a page that lives beneath it.
    const currentHref = nav
        .map((item) => item.href)
        .filter((href) => (href === '/portal' ? path === '/portal' : path.startsWith(href)))
        .sort((a, b) => b.length - a.length)[0];
    const isCurrent = (href: string) => href === currentHref;

    const navRow = (item: (typeof nav)[number], dense: boolean) => {
        const current = isCurrent(item.href);

        return (
            <Link
                key={item.key}
                href={item.href}
                aria-current={current ? 'page' : undefined}
                className={cx(
                    'flex shrink-0 items-center gap-3 rounded-sm px-3.5 text-ui transition-colors',
                    dense ? 'min-h-touch' : 'min-h-[50px]',
                    current
                        ? 'bg-gold-soft font-bold text-gold-dark'
                        : 'font-semibold text-ink hover:bg-sunken',
                )}
            >
                <Icon path={ICONS[item.key]} />
                {item.label}
                {item.count !== undefined && item.count > 0 && (
                    <span
                        aria-label={`${String(item.count)} open`}
                        className="ml-auto flex h-6 min-w-6 items-center justify-center rounded-full bg-gold px-1.5 text-label font-extrabold tracking-normal text-on-accent"
                    >
                        {item.count}
                    </span>
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
                <Link href="/portal" className="flex items-center px-7 pt-7 pb-6">
                    <GeoVerifyLockup caption="Business portal" />
                </Link>

                <nav className="flex flex-col gap-1 overflow-y-auto px-5" aria-label="Portal">
                    {nav.map((item) => navRow(item, false))}
                    {business !== null && (
                        <Link
                            href="/portal/claim"
                            className="mt-2 px-3.5 py-2 text-table font-bold text-faint hover:text-ink"
                        >
                            + Claim or add another business
                        </Link>
                    )}
                </nav>

                <div className="mt-auto flex flex-col gap-3 px-5 pb-6">
                    {business !== null && (
                        <Link
                            href={`/portal/businesses/${String(business.id)}/listings`}
                            className="rounded-sm bg-surface px-4 py-4 hover:bg-sunken"
                        >
                            <span className="flex items-baseline justify-between gap-2">
                                <span className="text-ui font-extrabold text-ink">Listing strength</span>
                                <span className="text-table font-bold text-gold-dark">
                                    {business.strength.percent}%
                                </span>
                            </span>
                            <span className="mt-2.5 block h-2 rounded-full bg-rule">
                                <span
                                    className="block h-2 rounded-full bg-gold"
                                    style={{ width: `${String(Math.max(4, business.strength.percent))}%` }}
                                />
                            </span>
                            <span className="mt-2.5 block text-[0.75rem] leading-snug text-muted">
                                {business.strength.hint}
                            </span>
                        </Link>
                    )}

                    <div className="flex items-center gap-3 px-2 py-2">
                        <Initials name={accountName} />
                        <span className="flex min-w-0 flex-col">
                            <span className="truncate text-ui font-bold text-ink">
                                {accountName}
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
                </div>
            </aside>

            <div className="border-b border-rule bg-raised lg:hidden">
                <div className="flex items-center gap-2.5 px-4 py-3">
                    <GeoVerifyMark size={30} />
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
                <nav className="flex gap-1 overflow-x-auto px-3 pb-3" aria-label="Portal">
                    {nav.map((item) => navRow(item, true))}
                </nav>
            </div>

            <div className="min-w-0">
                {(heading !== false || actions !== undefined) && (
                    <header className="border-b border-rule bg-raised">
                        <div
                            className={cx(
                                'flex min-h-[104px] flex-wrap items-center justify-between gap-4 px-5 py-5 lg:px-10',
                                width === 'form' && 'max-w-3xl',
                            )}
                        >
                            {heading}
                            {actions !== undefined && (
                                <div className="flex flex-wrap items-center gap-2.5">{actions}</div>
                            )}
                        </div>
                    </header>
                )}

                <main
                    className={cx(
                        'px-5 pt-8 pb-24 lg:px-10',
                        width === 'form' ? 'max-w-3xl' : 'max-w-[1240px]',
                    )}
                >
                    {notice}
                    {children}
                </main>
            </div>
        </div>
    );
}
