import { useState, type ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { cx } from '@/lib/cx';

export interface NavLink {
    label: string;
    href: string;
}

/** A top-level nav entry: a link, or a menu of links (Products). */
export interface NavItem {
    label: string;
    href?: string;
    children?: Array<NavLink & { caption?: string }>;
}

/** A nav entry with a menu: opens on hover or focus, and on tap. */
function NavMenu({ item, dark }: { item: NavItem; dark: boolean }) {
    const [open, setOpen] = useState(false);

    return (
        <div
            className="relative"
            onMouseEnter={() => {
                setOpen(true);
            }}
            onMouseLeave={() => {
                setOpen(false);
            }}
        >
            <button
                type="button"
                aria-expanded={open}
                aria-haspopup="true"
                onClick={() => {
                    setOpen(!open);
                }}
                className={cx('flex items-center gap-1 text-ui font-semibold', dark ? 'text-inverse/85 hover:text-inverse' : 'text-muted hover:text-ink')}
            >
                {item.label}
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" aria-hidden="true" className={cx('transition-transform', open && 'rotate-180')}>
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>
            {open && (
                <div className="absolute top-full left-0 z-30 pt-2">
                    <ul className="min-w-[240px] rounded-card border border-rule bg-raised p-2 text-ink shadow-card">
                        {(item.children ?? []).map((child) => (
                            <li key={child.href}>
                                <a href={child.href} className="block rounded-sm px-3 py-2.5 hover:bg-sunken">
                                    <span className="block text-ui font-extrabold">{child.label}</span>
                                    {child.caption !== undefined && <span className="block text-label text-muted">{child.caption}</span>}
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

/**
 * The public site's header: brand on the left, the doors in the middle, sign in
 * and get started on the right. Collapses to a menu below md.
 *
 * `tone="dark"` sits over a photograph or video; `tone="light"` over paper.
 */
export function SiteHeader({
    brand,
    links,
    signIn,
    getStarted,
    tone = 'light',
}: {
    brand: ReactNode;
    links: NavItem[];
    signIn: NavLink;
    getStarted: NavLink;
    tone?: 'light' | 'dark';
}) {
    // With the business portal closed, signing in and getting started mean
    // Enumerate: the portal's own door would only say "coming soon".
    const portalOpen = usePage().props.surfaces.portal;
    if (!portalOpen) {
        if (signIn.href.startsWith('/portal')) {
            signIn = { ...signIn, href: '/enumerate/sign-in' };
        }
        if (getStarted.href.startsWith('/portal')) {
            getStarted = { ...getStarted, href: '/portal/register?as=buyer&next=enumerate' };
        }
    }

    const [open, setOpen] = useState(false);
    const dark = tone === 'dark';

    return (
        <header className={cx('relative z-20', dark ? 'text-inverse' : 'border-b border-rule bg-raised/90 backdrop-blur')}>
            <div className="mx-auto flex max-w-[1200px] items-center gap-6 px-4 py-4 sm:px-6">
                <a href="/" aria-label="GeoVerify home" className="shrink-0">{brand}</a>
                <nav aria-label="Main" className="hidden flex-1 items-center gap-6 md:flex">
                    {links.map((link) =>
                        link.children !== undefined ? (
                            <NavMenu key={link.label} item={link} dark={dark} />
                        ) : (
                            <a
                                key={link.label}
                                href={link.href}
                                className={cx('text-ui font-semibold', dark ? 'text-inverse/85 hover:text-inverse' : 'text-muted hover:text-ink')}
                            >
                                {link.label}
                            </a>
                        ),
                    )}
                </nav>
                <div className="ml-auto hidden items-center gap-3 md:flex">
                    <Link
                        href={signIn.href}
                        className={cx('rounded-full px-4 py-2 text-ui font-semibold', dark ? 'border border-white/40 text-inverse hover:bg-white/10' : 'text-ink hover:text-gold-dark')}
                    >
                        {signIn.label}
                    </Link>
                    <Link
                        href={getStarted.href}
                        className={cx(
                            'rounded-full px-4 py-2 text-ui font-extrabold',
                            dark ? 'bg-white text-ink hover:bg-white/90' : 'bg-gold-dark text-on-accent hover:bg-gold',
                        )}
                    >
                        {getStarted.label}
                    </Link>
                </div>
                <button
                    type="button"
                    aria-expanded={open}
                    aria-label="Menu"
                    onClick={() => {
                        setOpen(!open);
                    }}
                    className={cx('ml-auto flex size-11 items-center justify-center rounded-sm md:hidden', dark ? 'text-inverse' : 'text-ink')}
                >
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                        {open ? <path d="M6 6l12 12M18 6 6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
                    </svg>
                </button>
            </div>
            {open && (
                <nav aria-label="Main" className="border-t border-rule bg-raised px-4 pb-4 text-ink md:hidden">
                    {links.flatMap((link) =>
                        (link.children ?? [{ label: link.label, href: link.href ?? '#' }]).map((entry) => (
                            <a
                                key={`${link.label}-${entry.href}`}
                                href={entry.href}
                                onClick={() => {
                                    setOpen(false);
                                }}
                                className="block min-h-touch py-3 text-body font-semibold"
                            >
                                {link.children !== undefined && <span className="mr-2 text-label font-semibold text-muted uppercase">{link.label}</span>}
                                {entry.label}
                            </a>
                        )),
                    )}
                    <div className="mt-2 flex gap-3">
                        <Link href={signIn.href} className="flex-1 rounded-full border border-rule-strong py-3 text-center text-ui font-semibold">
                            {signIn.label}
                        </Link>
                        <Link href={getStarted.href} className="flex-1 rounded-full bg-gold-dark py-3 text-center text-ui font-extrabold text-on-accent">
                            {getStarted.label}
                        </Link>
                    </div>
                </nav>
            )}
        </header>
    );
}

/** The small capitalised label above a section heading. */
export function Eyebrow({ children, className }: { children: ReactNode; className?: string | undefined }) {
    return <p className={cx('text-label font-extrabold tracking-[0.08em] text-gold-dark uppercase', className)}>{children}</p>;
}

export function SectionHeading({
    eyebrow,
    title,
    intro,
    align = 'left',
    tone = 'light',
}: {
    eyebrow?: string;
    title: ReactNode;
    intro?: ReactNode;
    align?: 'left' | 'center';
    tone?: 'light' | 'dark';
}) {
    return (
        <div className={cx(align === 'center' && 'mx-auto max-w-[720px] text-center')}>
            {eyebrow !== undefined && <Eyebrow className={tone === 'dark' ? 'text-logo' : undefined}>{eyebrow}</Eyebrow>}
            <h2
                className={cx(
                    'mt-2 font-display text-[1.9rem] leading-[1.1] font-extrabold tracking-[-0.02em] sm:text-[2.4rem]',
                    tone === 'dark' ? 'text-inverse' : 'text-ink',
                )}
            >
                {title}
            </h2>
            {intro !== undefined && (
                <p className={cx('mt-3 max-w-[62ch] text-body', align === 'center' && 'mx-auto', tone === 'dark' ? 'text-inverse/75' : 'text-muted')}>
                    {intro}
                </p>
            )}
        </div>
    );
}

/** A tick, for the short lists of what something includes. */
export function Tick({ className }: { className?: string }) {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" className={cx('shrink-0', className)}>
            <circle cx="12" cy="12" r="11" fill="currentColor" opacity="0.14" />
            <path d="m7 12.5 3.2 3.2L17 9" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

/** The site footer, shared by the home page and Enumerate. */
export function SiteFooter() {
    const portalOpen = usePage().props.surfaces.portal;
    const columns: Array<{ title: string; links: NavLink[] }> = [
        {
            title: 'Get started',
            links: portalOpen
                ? [
                      { label: 'Create a business account', href: '/portal/register' },
                      { label: 'Sign in to your business', href: '/portal/sign-in' },
                      { label: 'Staff sign in', href: '/login' },
                  ]
                : [
                      { label: 'Create an account', href: '/portal/register?as=buyer&next=enumerate' },
                      { label: 'Sign in to Enumerate', href: '/enumerate/sign-in' },
                      { label: 'Staff sign in', href: '/login' },
                  ],
        },
        {
            title: 'Products',
            links: [
                { label: 'Business directory', href: '/directory' },
                { label: 'Enumerate', href: '/enumerate' },
                ...(portalOpen ? [{ label: 'Business portal', href: '/portal/sign-in' }] : []),
            ],
        },
        {
            title: 'Company',
            links: [
                { label: 'How it works', href: '/directory/how-verification-works' },
                { label: 'Become an agent', href: '/become-an-agent' },
                { label: 'Pricing', href: '/enumerate#pricing' },
                { label: 'Privacy policy', href: '/privacy' },
                { label: 'Terms of service', href: '/terms' },
            ],
        },
    ];

    return (
        <footer className="bg-[#0F1A17] text-inverse">
            <div className="mx-auto grid max-w-[1200px] gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_repeat(3,1fr)]">
                <div>
                    <a href="/" aria-label="GeoVerify home" className="inline-block">
                        <GeoVerifyLockup tone="light" />
                    </a>
                    <p className="mt-4 max-w-[38ch] text-ui text-inverse/70">
                        A national platform for business registration and geographic mapping, built to give Nigeria one
                        trustworthy map of where business happens.
                    </p>
                </div>
                {columns.map((column) => (
                    <div key={column.title}>
                        <p className="text-label font-extrabold tracking-[0.08em] text-logo uppercase">{column.title}</p>
                        <ul className="mt-3 flex flex-col gap-2">
                            {column.links.map((link) => (
                                <li key={link.href}>
                                    <a href={link.href} className="text-ui text-inverse/80 hover:text-inverse">
                                        {link.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <div className="border-t border-white/10">
                <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-3 px-4 py-5 text-label text-inverse/55 sm:px-6">
                    <span>© {new Date().getFullYear()} GeoVerify. All rights reserved.</span>
                    <span>Registry data from CAC and FIRS. Imagery contains modified Copernicus Sentinel data.</span>
                </div>
            </div>
        </footer>
    );
}
