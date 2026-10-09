import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { GeoVerifyLockup, GeoVerifyMark } from '@/components/GeoVerifyMark';

/**
 * The sign-in screens from the login mockups (September 2026).
 *
 * Two layouts on one ground: the dark hex field. The business portal puts it on
 * the left with the form on white to the right; the investor portal mirrors
 * it, form left and the field as an inset panel on the right. On a phone the
 * field shrinks to a band above the form, which then rises over it as a white
 * sheet, as the mobile boards draw it.
 */

/** A honeycomb, drawn once as a pattern, for the dark panels. */
export function HexField({ className }: { className?: string }) {
    return (
        <svg aria-hidden="true" className={cx('pointer-events-none absolute inset-0 h-full w-full', className)}>
            <defs>
                <pattern id="gv-hex-field" width="56" height="97" patternUnits="userSpaceOnUse">
                    <path
                        d="M28 0 56 16.2v32.4L28 64.8 0 48.6V16.2zM28 64.8V97"
                        fill="none"
                        stroke="#FFFFFF"
                        strokeOpacity="0.06"
                        strokeWidth="1"
                    />
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#gv-hex-field)" />
        </svg>
    );
}

/**
 * The business portal's door. Showcase on the left from lg up; below it, a dark
 * band carries the mark and the greeting and the form sits on a white sheet.
 */
export function BusinessAuthLayout({
    topRight,
    mobileTitle = 'Welcome back',
    mobileSubtitle = 'Verified businesses. Payment-protected orders.',
    showcase,
    bare = false,
    children,
}: {
    /** No dark band on a phone: the code screen is plain white, as drawn. */
    bare?: boolean;
    topRight?: ReactNode;
    mobileTitle?: string;
    mobileSubtitle?: string;
    /** The left panel's content. Defaults to the business showcase. */
    showcase?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink lg:grid lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
            <aside className="relative hidden overflow-hidden bg-[#0F1A17] text-inverse lg:flex lg:min-h-dvh lg:flex-col">
                <HexField />
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute top-[27%] right-[-18%] size-[560px] rounded-full bg-[#16302A]/70"
                />
                <div className="relative flex flex-1 flex-col px-14 pt-12 pb-10">
                    <a href="/" aria-label="GeoVerify home" className="self-start">
                        <GeoVerifyLockup size={46} caption="Nigeria business directory" tone="light" />
                    </a>
                    {showcase ?? <BusinessShowcase />}
                </div>
            </aside>

            {/* The phone band. */}
            <div className={cx('relative overflow-hidden bg-[#0F1A17] px-6 pt-8 pb-16 text-inverse lg:hidden', bare && 'hidden')}>
                <HexField />
                <div className="relative">
                    <span className="flex items-center gap-2.5">
                        <a href="/" aria-label="GeoVerify home">
                            <GeoVerifyMark size={40} ink="light" />
                        </a>
                        <span className="font-wordmark text-[1.5rem] font-bold text-logo">GeoVerify</span>
                    </span>
                    <p className="mt-6 font-display text-display-l">{mobileTitle}</p>
                    <p className="mt-2 text-ui text-inverse/75">{mobileSubtitle}</p>
                </div>
            </div>

            <main
                className={cx(
                    'relative flex flex-col bg-raised px-6 pb-8 lg:mt-0 lg:min-h-dvh lg:rounded-none lg:px-14 lg:pt-10',
                    bare ? 'min-h-dvh pt-8' : '-mt-8 min-h-[60dvh] rounded-t-[28px] pt-6',
                )}
            >
                {topRight !== undefined && (
                    <div className="hidden justify-end text-ui text-muted lg:flex">{topRight}</div>
                )}
                <div className="flex flex-1 flex-col lg:items-center lg:justify-center">
                    <div className="w-full lg:max-w-[460px]">{children}</div>
                </div>
            </main>
        </div>
    );
}

/**
 * The left panel of the business door: the promise, an example listing, the
 * held payment, and the three proofs. The listing is an example and says so:
 * a sign-in page is not the place to put a real business in front of
 * everybody who arrives.
 */
function BusinessShowcase() {
    return (
        <>
            <h1 className="mt-20 max-w-[21ch] font-display text-[3rem] leading-[1.04] font-extrabold tracking-[-0.03em]">
                Every business here was verified on the ground.
            </h1>
            <p className="mt-5 max-w-[46ch] text-body text-inverse/80">
                Field agents visit, photograph and geo-tag each listing. Payments stay held until the
                order arrives.
            </p>

            <div className="relative mt-14 h-[270px] max-w-[600px]">
                <div className="absolute top-0 left-0 w-[375px] rounded-[18px] bg-raised p-5 text-ink shadow-[0_18px_48px_rgb(0_0_0/0.35)]">
                    <div className="flex items-center justify-between gap-3">
                        <span className="rounded-full bg-gold-soft px-2.5 py-1 text-table font-bold text-gold-dark">
                            Verified · 12 Jun 2026
                        </span>
                        <span className="text-table font-bold text-gold-dark">Open now</span>
                    </div>
                    <p className="mt-3 text-body font-extrabold">Amaka Fresh Foods</p>
                    <p className="mt-1 text-ui text-muted">Groceries · Wuse 2, Abuja</p>
                    <p className="mt-1.5 flex items-center justify-between numeric-mono text-[0.75rem] text-muted">
                        <span>cell 881f1d4a3bfffff · agent FA-0231</span>
                        <span className="font-sans text-[0.6875rem] font-bold tracking-[0.05em] text-faint uppercase">Example</span>
                    </p>
                </div>
                <div className="absolute top-[146px] left-[262px] w-[335px] rounded-[18px] border border-white/10 bg-[#1B2724] p-5 shadow-[0_18px_48px_rgb(0_0_0/0.35)]">
                    <p className="flex items-center gap-2 text-table font-extrabold tracking-[0.03em] text-logo uppercase">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                            <path d="M5.5 10.5h13v10h-13zM8.5 10.5V7a3.5 3.5 0 0 1 7 0v3.5" />
                        </svg>
                        Held until delivery
                    </p>
                    <p className="mt-2 font-display text-[1.875rem] font-extrabold">₦211,500</p>
                    <p className="mt-1 text-ui text-inverse/75">Released after the buyer confirms delivery</p>
                </div>
            </div>

            <div className="mt-auto grid grid-cols-3 gap-6 border-t border-white/10 pt-7">
                {(
                    [
                        ['Geo-tagged', 'Every listing pinned on site'],
                        ['Agent-verified', 'Photos, ID and CAC checks'],
                        ['Payment-protected', 'Money held until delivery'],
                    ] as const
                ).map(([title, body]) => (
                    <div key={title}>
                        <p className="text-ui font-extrabold">{title}</p>
                        <p className="mt-1 text-table text-inverse/65">{body}</p>
                    </div>
                ))}
            </div>
        </>
    );
}

/**
 * The investor portal's door: the form on white to the left, the dark field as
 * an inset panel on the right. The panel's content is handed in, because it
 * shows the live register.
 */
export function InvestorAuthLayout({ panel, children }: { panel: ReactNode; children: ReactNode }) {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
            <main className="flex min-h-dvh flex-col px-6 pt-8 pb-8 sm:px-14 lg:pt-10">
                <div className="flex items-center justify-between gap-4">
                    <a href="/" aria-label="GeoVerify home">
                        <GeoVerifyLockup size={42} caption="Investor & discovery" />
                    </a>
                    <Link href="/directory" className="text-ui font-bold text-ink hover:text-gold">
                        Business directory <span aria-hidden="true">→</span>
                    </Link>
                </div>
                <div className="flex flex-1 flex-col justify-center py-10">
                    <div className="w-full max-w-[460px] lg:ml-20">{children}</div>
                </div>
            </main>

            <aside className="relative m-4 hidden overflow-hidden rounded-[28px] bg-[#0F1A17] text-inverse lg:block">
                <HexField />
                <div className="relative flex h-full flex-col px-12 pt-14 pb-12">{panel}</div>
            </aside>
        </div>
    );
}

/** The two-way switch between buying and running a business. */
export function AudienceTabs({
    value,
    onChange,
}: {
    value: 'buyer' | 'business';
    onChange: (value: 'buyer' | 'business') => void;
}) {
    return (
        <div role="tablist" aria-label="Who is signing in" className="grid grid-cols-2 gap-1 rounded-[14px] bg-sunken p-1.5">
            {(
                [
                    ['buyer', 'I’m buying'],
                    ['business', 'I run a business'],
                ] as const
            ).map(([key, label]) => (
                <button
                    key={key}
                    type="button"
                    role="tab"
                    aria-selected={value === key}
                    onClick={() => {
                        onChange(key);
                    }}
                    className={cx(
                        'min-h-touch-lg rounded-[10px] text-ui transition-colors',
                        value === key ? 'bg-raised font-extrabold text-ink shadow-card' : 'font-semibold text-muted hover:text-ink',
                    )}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}

export function OrRule({ label = 'or' }: { label?: string }) {
    return (
        <div className="flex items-center gap-4 text-table text-muted" role="separator">
            <span className="h-px flex-1 bg-rule" />
            {label}
            <span className="h-px flex-1 bg-rule" />
        </div>
    );
}
