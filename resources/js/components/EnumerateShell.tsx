import type { ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';
import { kobo, type EnumerateFrame } from '@/lib/enumerate';

type NavKey = 'home' | 'new' | 'requests' | 'wallet' | 'support' | 'overview' | 'projects' | 'team';

interface NavItem {
    key: NavKey;
    label: string;
    href: string;
    icon: string;
}

/** 24px outline marks, drawn in currentColor. */
const ICON = {
    home: 'M3 10.5 12 3l9 7.5M5.5 9v11h13V9',
    plus: 'M12 5v14M5 12h14',
    shield: 'M12 3 4.5 6v5.5c0 4.6 3.2 8.3 7.5 9.5 4.3-1.2 7.5-4.9 7.5-9.5V6zM9 12l2 2 4-4',
    wallet: 'M3.5 6.5h17v12h-17zM3.5 10h17M16 14.5h1.5',
    chat: 'M4 5h16v11H9l-5 4z',
    layers: 'M12 3 3 8l9 5 9-5zM3 13l9 5 9-5',
    people: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6M16 4.3a3.5 3.5 0 0 1 0 6.4M17.5 14.3c2.3.6 4 2.6 4 5.7',
};

/** Acting as oneself: the individual portal, boards 28 to 32. */
const PERSONAL: NavItem[] = [
    { key: 'home', label: 'Home', href: '/enumerate', icon: ICON.home },
    { key: 'new', label: 'New verification', href: '/enumerate/verify', icon: ICON.plus },
    { key: 'requests', label: 'My verifications', href: '/enumerate/verifications', icon: ICON.shield },
    { key: 'wallet', label: 'Wallet', href: '/enumerate/wallet', icon: ICON.wallet },
    { key: 'support', label: 'Complaints', href: '/enumerate/support', icon: ICON.chat },
];

/** Acting for an organisation: board 34's sidebar. */
const ORGANISATION: NavItem[] = [
    { key: 'overview', label: 'Overview', href: '/enumerate/organisation', icon: ICON.home },
    { key: 'projects', label: 'Enumeration projects', href: '/enumerate/organisation/projects', icon: ICON.layers },
    { key: 'requests', label: 'Verifications', href: '/enumerate/verifications', icon: ICON.shield },
    { key: 'new', label: 'New verification', href: '/enumerate/verify', icon: ICON.plus },
    { key: 'wallet', label: 'Wallet', href: '/enumerate/wallet', icon: ICON.wallet },
    { key: 'team', label: 'Team & roles', href: '/enumerate/organisation/team', icon: ICON.people },
    { key: 'support', label: 'Complaints', href: '/enumerate/support', icon: ICON.chat },
];

function Icon({ path }: { path: string }) {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="shrink-0">
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

/** "Enumerate by GeoVerify", or "for Organisations" when acting for one. */
export function EnumerateLockup({ size = 40, tone = 'auto', caption = 'by GeoVerify' }: { size?: number; tone?: 'auto' | 'light'; caption?: string }) {
    return (
        <span className="flex items-center gap-2.5">
            <GeoVerifyMark size={size} title="Enumerate" ink={tone === 'light' ? 'light' : 'auto'} />
            <span className="flex flex-col">
                <span className="font-wordmark text-[1.375rem] leading-none font-bold tracking-[-0.01em] text-logo">Enumerate</span>
                <span className={cx('mt-1 text-[0.6875rem] leading-none font-bold', tone === 'light' ? 'text-inverse/70' : 'text-muted')}>{caption}</span>
            </span>
        </span>
    );
}

/** The wallet in the header: the balance, and a way to add to it. */
function WalletPill({ minor, label }: { minor: number; label: string }) {
    return (
        <span className="flex items-stretch overflow-hidden rounded-card border-2 border-gold">
            <span className="flex flex-col justify-center px-3.5 py-1.5">
                <span className="text-[0.625rem] font-extrabold tracking-[0.06em] text-muted uppercase">{label}</span>
                <span className="font-display text-[1.125rem] leading-tight font-extrabold text-ink numeric-mono">{kobo(minor, 2)}</span>
            </span>
            <Link href="/enumerate/wallet#fund" className="flex items-center bg-gold-soft px-3.5 text-ui font-extrabold text-gold-dark hover:bg-gold hover:text-on-accent">
                + Fund
            </Link>
        </span>
    );
}

/** Who the portal is acting as. Posting the choice changes the wallet everything uses. */
function Switcher({ frame, dark }: { frame: EnumerateFrame; dark: boolean }) {
    if (frame.organisations.length === 0) {
        return null;
    }

    return (
        <label className="block px-4 pb-4">
            <span className="sr-only">Acting as</span>
            <select
                value={frame.organisation === null ? '' : String(frame.organisation.id)}
                onChange={(e) => {
                    router.post('/enumerate/switch', { organisation: e.target.value === '' ? null : Number(e.target.value) });
                }}
                className={cx(
                    'h-12 w-full rounded-sm border px-3 text-ui font-bold',
                    dark ? 'border-white/15 bg-white/5 text-inverse' : 'border-rule-strong bg-raised text-ink',
                )}
            >
                <option value="">{frame.name} · Individual</option>
                {frame.organisations.map((o) => (
                    <option key={o.id} value={o.id}>
                        {o.name}
                        {o.status === 'pending' ? ' · awaiting approval' : ''}
                    </option>
                ))}
            </select>
        </label>
    );
}

/**
 * Enumerate's frame, to the verification portal mockup.
 *
 * Acting as oneself it is boards 28 to 32: a white sidebar and the dark card
 * pointing a company at the organisation account. Acting for an organisation
 * it is board 34: the dark sidebar, the organisation's own navigation, and the
 * account manager at the foot. The wallet in the header is always the one
 * being spent from.
 */
export function EnumerateShell({
    current,
    frame,
    title,
    crumbs,
    actions,
    children,
}: {
    current: NavKey | null;
    frame: EnumerateFrame;
    title: ReactNode;
    crumbs?: ReactNode | undefined;
    actions?: ReactNode | undefined;
    children: ReactNode;
}) {
    const flash = usePage().props.flash.status;
    const org = frame.organisation;
    const dark = org !== null;
    const nav = dark ? ORGANISATION : PERSONAL;

    const signOut = () => {
        router.post('/portal/sign-out');
    };

    const navRow = (item: NavItem, dense: boolean) => {
        const active = item.key === current;

        return (
            <Link
                key={item.key}
                href={item.href}
                aria-current={active ? 'page' : undefined}
                className={cx(
                    'flex shrink-0 items-center gap-3 rounded-sm px-3.5 text-ui transition-colors',
                    dense ? 'min-h-touch' : 'min-h-[44px]',
                    dark
                        ? active ? 'bg-white/10 font-bold text-inverse' : 'font-semibold text-inverse/75 hover:bg-white/5 hover:text-inverse'
                        : active ? 'bg-gold-soft font-bold text-gold-dark' : 'font-semibold text-ink hover:bg-sunken',
                )}
            >
                <Icon path={item.icon} />
                {item.label}
                {item.key === 'requests' && frame.active > 0 && (
                    <span className="ml-auto flex h-6 min-w-6 items-center justify-center rounded-full bg-gold px-1.5 text-label font-extrabold text-on-accent" aria-label={`${String(frame.active)} in progress`}>
                        {frame.active}
                    </span>
                )}
            </Link>
        );
    };

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink lg:grid lg:grid-cols-[252px_minmax(0,1fr)]">
            <aside className={cx('hidden lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col', dark ? 'bg-[#0F1A17] text-inverse' : 'border-r border-rule bg-raised')}>
                <Link href="/enumerate" className="flex items-center px-6 pt-6 pb-5">
                    <EnumerateLockup tone={dark ? 'light' : 'auto'} caption={dark ? 'for Organisations' : 'by GeoVerify'} />
                </Link>

                {org !== null && (
                    <div className="mx-4 mb-3 flex items-center gap-3 rounded-sm bg-white/5 px-3 py-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-[8px] bg-raised text-table font-extrabold text-ink">{initials(org.name)}</span>
                        <span className="min-w-0">
                            <span className="block truncate text-ui font-extrabold">{org.name}</span>
                            <span className="block text-[0.75rem] text-inverse/60">
                                {org.status === 'pending' ? 'Awaiting approval' : `${String(org.seats)} ${org.seats === 1 ? 'seat' : 'seats'}`} · {org.roleLabel}
                            </span>
                        </span>
                    </div>
                )}

                <Switcher frame={frame} dark={dark} />

                <nav className="flex flex-col gap-1 px-4" aria-label="Enumerate">
                    {nav.map((item) => navRow(item, false))}
                </nav>

                <div className="mt-auto flex flex-col gap-3 px-4 pb-5">
                    {org !== null ? (
                        <div className="flex items-center gap-3 rounded-sm bg-white/5 px-3 py-3">
                            <span aria-hidden="true" className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gold-soft text-table font-extrabold text-gold-dark">
                                {org.accountManager === null ? 'GV' : initials(org.accountManager)}
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[0.75rem] text-inverse/60">Your account manager</span>
                                <span className="block truncate text-ui font-bold">{org.accountManager ?? 'Being assigned'}</span>
                            </span>
                        </div>
                    ) : (
                        <Link href="/enumerate/organisations/new" className="block rounded-sm bg-ink px-4 py-4 text-inverse hover:bg-ink/90">
                            <span className="block text-ui font-extrabold">Verifying for a company?</span>
                            <span className="mt-1.5 block text-[0.8125rem] leading-snug text-inverse/75">
                                Organisations get Tier 3 in bulk, custom enumeration and team seats.
                            </span>
                            <span className="mt-2 block text-table font-extrabold text-logo">Open an organisation account →</span>
                        </Link>
                    )}

                    <div className="flex items-center gap-3 px-1 py-1">
                        <span aria-hidden="true" className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gold-soft text-ui font-extrabold text-gold-dark">
                            {initials(frame.name)}
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span className={cx('truncate text-ui font-bold', dark ? 'text-inverse' : 'text-ink')}>{frame.name}</span>
                            <span className={cx('text-[0.75rem]', dark ? 'text-inverse/60' : 'text-faint')}>{org === null ? 'Individual account' : org.roleLabel}</span>
                            <button type="button" onClick={signOut} className={cx('self-start text-[0.75rem] font-semibold', dark ? 'text-inverse/60 hover:text-inverse' : 'text-faint hover:text-ink')}>
                                Sign out
                            </button>
                        </span>
                    </div>
                </div>
            </aside>

            <div className={cx('border-b lg:hidden', dark ? 'border-white/10 bg-[#0F1A17] text-inverse' : 'border-rule bg-raised')}>
                <div className="flex items-center gap-2.5 px-4 py-3">
                    <EnumerateLockup size={30} tone={dark ? 'light' : 'auto'} caption={org?.name ?? 'by GeoVerify'} />
                    <button type="button" onClick={signOut} className="ml-auto min-h-touch rounded-sm border border-rule-strong bg-raised px-3 text-ui font-bold text-ink">
                        Sign out
                    </button>
                </div>
                <Switcher frame={frame} dark={dark} />
                <nav className="flex gap-1 overflow-x-auto px-3 pb-3" aria-label="Enumerate">
                    {nav.map((item) => navRow(item, true))}
                </nav>
            </div>

            <div className="min-w-0">
                <header className="border-b border-rule bg-raised">
                    <div className="flex min-h-[88px] flex-wrap items-center justify-between gap-4 px-5 py-4 lg:px-10">
                        <div className="min-w-0">
                            {crumbs !== undefined && <p className="mb-1 text-table font-semibold text-muted">{crumbs}</p>}
                            <h1 className="font-display text-display-l text-ink">{title}</h1>
                        </div>
                        <div className="flex flex-wrap items-center gap-2.5">
                            {actions}
                            <WalletPill minor={frame.walletMinor} label={org === null ? 'Wallet' : 'Organisation wallet'} />
                        </div>
                    </div>
                </header>

                <main className="max-w-[1180px] px-5 pt-7 pb-24 lg:px-10">
                    {frame.invitations.map((i) => (
                        <div key={i.id} className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-sm border border-gold/40 bg-gold-soft px-4 py-3">
                            <p className="max-w-none text-ui text-gold-dark">
                                <span className="font-extrabold">{i.organisation}</span> has invited you to join as {i.role}.
                            </p>
                            <button
                                type="button"
                                onClick={() => { router.post(`/enumerate/invitations/${String(i.id)}/accept`); }}
                                className="h-9 rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                            >
                                Accept
                            </button>
                        </div>
                    ))}
                    {org?.status === 'pending' && (
                        <p className="mb-4 max-w-none rounded-sm bg-amber-soft px-4 py-3 text-ui text-amber-ink">
                            <span className="font-bold">Awaiting approval.</span> You can fund the wallet and run checks now; bulk verification and projects open once we approve the organisation.
                        </p>
                    )}
                    {flash !== null && (
                        <p role="status" className="mb-6 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                            {flash}
                        </p>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
