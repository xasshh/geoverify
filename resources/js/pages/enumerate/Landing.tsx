import { useState, type ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { EnumerateLockup } from '@/components/EnumerateShell';
import { HexField } from '@/components/AuthLayouts';
import { Eyebrow, SectionHeading, SiteFooter, SiteHeader, Tick, type NavItem } from '@/components/PublicSite';
import { companyType, kobo, priceLabel, type Prices } from '@/lib/enumerate';
import { STATE_TILES } from '@/lib/nigeria';
import { cx } from '@/lib/cx';

interface Props {
    prices: Prices;
    coveredStates: string[];
}

type SearchBy = 'name' | 'rc' | 'tin';

interface Match {
    name: string;
    rcNumber: string;
    companyType: string;
    status: string | null;
    place: string | null;
}

const NAV: NavItem[] = [
    { label: 'Registry', href: '/directory' },
    {
        label: 'Products',
        children: [{ label: 'Enumerate', href: '/enumerate', caption: 'Verify any business in Nigeria' }],
    },
    { label: 'How it works', href: '#how' },
    { label: 'Pricing', href: '#pricing' },
    { label: 'Become an agent', href: '/become-an-agent' },
];

/**
 * Enumerate's front door for somebody who is not signed in.
 *
 * The search works without an account: it asks the register (through the
 * same lookup a requester uses, cached and limited per connection) and lists
 * what it finds, each with a way to verify it. Verifying is the paid part, so
 * that is where sign in begins.
 */
export default function Landing({ prices, coveredStates }: Props) {
    const tier3 = prices.tier3['30'] ?? Object.values(prices.tier3).at(-1) ?? 0;

    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink">
            <Head title="Enumerate: verify any business in Nigeria" />

            <SiteHeader
                brand={<EnumerateLockup size={36} />}
                links={NAV}
                signIn={{ label: 'Sign in', href: '/enumerate/sign-in' }}
                getStarted={{ label: 'Get started', href: '/enumerate/sign-in' }}
            />

            <Hero tier1={prices.tier1} />
            <SourcesStrip />
            <HowItWorks />
            <Ladder tier1={prices.tier1} tier2={prices.tier2} tier3={tier3} />
            <WhyEnumerate />
            <TheReport />
            <WhoItsFor />
            <FieldNetwork covered={coveredStates} />
            <Faq tier1={prices.tier1} />
            <Closing />
            <SiteFooter />
        </div>
    );
}

function Hero({ tier1 }: { tier1: number }) {
    return (
        <section className="relative overflow-hidden bg-sunken">
            <HexField className="opacity-60 [&_path]:stroke-[#0E5C55]" />
            <div className="relative mx-auto grid max-w-[1200px] gap-12 px-4 pt-12 pb-16 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:pt-16 lg:pb-20">
                <div>
                    <span className="inline-flex items-center gap-2 rounded-full bg-green-soft px-3 py-1.5 text-label font-bold text-green">
                        <Tick className="text-green" />
                        Checked against CAC, FIRS and on the ground
                    </span>
                    <h1 className="mt-5 font-display text-[3rem] leading-[0.98] font-extrabold tracking-[-0.03em] sm:text-[4.2rem]">
                        Search. Verify.
                        <br />
                        <span className="text-gold-dark">Trust.</span>
                    </h1>
                    <p className="mt-5 max-w-[46ch] text-body text-muted">
                        Look up any Nigerian business, confirm it is registered, and send a trained officer to check it is
                        really there and really trading, before you pay, partner or hire.
                    </p>
                    <SearchBox tier1={tier1} />
                </div>
                <HeroArt />
            </div>
        </section>
    );
}

/**
 * The free search. Name and CAC number are asked of the register; TIN is part
 * of every registry check rather than something to search by, and the tab
 * says so instead of pretending.
 */
function SearchBox({ tier1 }: { tier1: number }) {
    const [by, setBy] = useState<SearchBy>('rc');
    const [q, setQ] = useState('');
    const [state, setState] = useState<'idle' | 'searching' | 'done' | 'error'>('idle');
    const [matches, setMatches] = useState<Match[]>([]);
    const [message, setMessage] = useState<string | null>(null);

    const search = async (): Promise<void> => {
        if (by === 'tin' || q.trim().length < 3) {
            return;
        }

        setState('searching');
        setMessage(null);

        try {
            const params = new URLSearchParams({ by, q: q.trim() });
            const response = await fetch(`/enumerate/search?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const body = (await response.json()) as { matches?: Match[]; message?: string };

            if (!response.ok) {
                setMessage(
                    response.status === 429 && body.message === undefined
                        ? 'That is a lot of searches in a minute. Wait a moment and try again.'
                        : (body.message ?? 'Search did not work just now.'),
                );
                setState('error');

                return;
            }

            setMatches(body.matches ?? []);
            setState('done');
        } catch {
            setMessage('Could not reach the register. Check your connection and try again.');
            setState('error');
        }
    };

    const placeholder =
        by === 'rc' ? 'CAC number, e.g. RC 1482093 or BN 3300112' : 'Tax ID (TIN)';

    return (
        <div className="mt-7 max-w-[560px]">
            <form
                className="rounded-card border border-rule bg-raised p-3 shadow-card"
                onSubmit={(e) => {
                    e.preventDefault();
                    void search();
                }}
            >
                <div role="tablist" aria-label="Search by" className="flex gap-1">
                    {(
                        [
                            ['rc', 'CAC number'],
                            ['tin', 'TIN'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={by === key}
                            onClick={() => {
                                setBy(key);
                                setState('idle');
                                setMessage(null);
                            }}
                            className={cx(
                                'min-h-[36px] rounded-full px-3.5 text-table',
                                by === key ? 'bg-[#0F1A17] font-extrabold text-inverse' : 'font-semibold text-muted hover:text-ink',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <div className="mt-3 flex gap-2">
                    <input
                        aria-label={placeholder}
                        value={q}
                        disabled={by === 'tin'}
                        onChange={(e) => {
                            setQ(e.target.value);
                        }}
                        placeholder={placeholder}
                        className="h-12 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-4 text-ui text-ink placeholder:text-faint focus:border-gold focus:outline-none disabled:bg-sunken"
                    />
                    <button
                        type="submit"
                        disabled={by === 'tin' || q.trim().length < 3 || state === 'searching'}
                        className="h-12 rounded-sm bg-gold-dark px-6 text-ui font-extrabold text-on-accent hover:bg-gold disabled:opacity-50"
                    >
                        {state === 'searching' ? 'Searching' : 'Search'}
                    </button>
                </div>
                {by === 'tin' && (
                    <p className="mt-2 text-label text-muted">
                        The TIN is confirmed with FIRS in every registry check. Search by CAC number to find the
                        business first.
                    </p>
                )}
            </form>

            <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-label text-muted">
                <li className="flex items-center gap-1.5">
                    <Tick className="text-green" /> Search is free
                </li>
                <li className="flex items-center gap-1.5">
                    <Tick className="text-green" /> Registry check {tier1 === 0 ? 'free' : `from ${kobo(tier1)}`}
                </li>
                <li className="flex items-center gap-1.5">
                    <Tick className="text-green" /> PDF report for every check
                </li>
            </ul>

            {state === 'error' && message !== null && (
                <p role="alert" className="mt-3 rounded-sm bg-amber-soft px-3 py-2 text-ui text-amber-ink">
                    {message}
                </p>
            )}

            {state === 'done' && (
                <div className="mt-3 rounded-card border border-rule bg-raised shadow-card">
                    {matches.length === 0 ? (
                        <p className="px-4 py-3 text-ui text-muted">
                            Nothing on the register under that number. Check it, or
                            try the CAC number from an invoice or letterhead.
                        </p>
                    ) : (
                        <ul>
                            {matches.map((match) => (
                                <li
                                    key={`${match.rcNumber}-${match.companyType}`}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-rule px-4 py-3 last:border-b-0"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-ui font-extrabold text-ink">{match.name}</p>
                                        <p className="text-label text-muted">
                                            {companyType(match.companyType)} {match.rcNumber}
                                            {match.status !== null && ` · ${match.status}`}
                                            {match.place !== null && ` · ${match.place}`}
                                        </p>
                                    </div>
                                    <Link
                                        href={`/enumerate/verify?by=rc&q=${encodeURIComponent(match.rcNumber)}&tier=1`}
                                        className="rounded-full bg-gold-dark px-4 py-2 text-label font-extrabold text-on-accent hover:bg-gold"
                                    >
                                        Verify
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

/** The hero's picture: a pin, a verified card, an activity log, a report. */
function HeroArt() {
    return (
        <div aria-hidden="true" className="relative hidden min-h-[440px] lg:block">
            <div className="absolute top-2 right-0 size-[420px] rounded-full bg-[radial-gradient(circle,rgba(75,184,176,0.28),rgba(75,184,176,0.05)_70%)]" />
            <div className="absolute top-[70px] left-[150px] flex flex-col items-center">
                <span className="flex size-16 items-center justify-center rounded-full border-2 border-gold-dark bg-raised shadow-card">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#0E5C55" strokeWidth="2">
                        <path d="M12 21s-6-5.6-6-11a6 6 0 1 1 12 0c0 5.4-6 11-6 11z" />
                        <circle cx="12" cy="10" r="2.2" />
                    </svg>
                </span>
                <span className="mt-2 rounded-sm bg-raised px-2 py-1 numeric-mono text-label text-ink shadow-card">9.0631, 7.4952 · ±4 m</span>
            </div>
            <div className="absolute top-[178px] left-[24px] w-[300px] rounded-card border border-rule bg-raised p-4 shadow-card">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <p className="text-ui font-extrabold text-ink">Kora Build Supplies Ltd</p>
                        <p className="text-label text-muted">RC 1482093 · Gwarinpa, Abuja</p>
                    </div>
                    <span className="rounded-full bg-gold-dark px-2 py-0.5 text-[0.7rem] font-extrabold text-on-accent">Verified</span>
                </div>
                <div className="mt-3 flex gap-2">
                    <span className="rounded-sm bg-green-soft px-2 py-1 text-[0.7rem] font-bold text-green">✓ CAC + TIN</span>
                    <span className="rounded-sm bg-green-soft px-2 py-1 text-[0.7rem] font-bold text-green">✓ Location</span>
                    <span className="rounded-sm bg-sunken px-2 py-1 text-[0.7rem] font-bold text-muted">Day 12</span>
                </div>
            </div>
            <div className="absolute top-[150px] right-[6px] w-[210px] rounded-card bg-[#0F1A17] p-3 text-inverse shadow-card">
                <p className="text-[0.65rem] font-extrabold tracking-[0.08em] text-logo uppercase">Daily activity log</p>
                <div className="mt-2 grid grid-cols-10 gap-1">
                    {Array.from({ length: 30 }, (_, i) => (
                        <span
                            key={i}
                            className={cx('aspect-square rounded-[2px]', i % 7 === 6 ? 'bg-white/10' : i < 22 ? 'bg-logo' : 'bg-white/20')}
                        />
                    ))}
                </div>
                <p className="mt-2 text-[0.7rem] text-inverse/75">Day 12: Open 8:05 to 18:10 · 7 staff · 3 deliveries</p>
            </div>
            <div className="absolute top-[318px] left-[170px] flex items-center gap-3 rounded-card border border-rule bg-raised px-3 py-2.5 shadow-card">
                <span className="rounded-[4px] bg-alert-soft px-1.5 py-1 text-[0.6rem] font-extrabold text-alert">PDF</span>
                <span>
                    <span className="block text-label font-extrabold text-ink">Verification report</span>
                    <span className="block text-[0.7rem] text-muted">QR checkable · ready to share</span>
                </span>
            </div>
        </div>
    );
}

function SourcesStrip() {
    return (
        <div className="border-y border-rule bg-raised">
            <div className="mx-auto flex max-w-[1200px] flex-wrap items-center gap-x-10 gap-y-2 px-4 py-4 sm:px-6">
                <span className="text-[0.7rem] font-extrabold tracking-[0.08em] text-faint uppercase">Every check uses</span>
                {['CAC company register', 'FIRS TIN register', 'GPS-tagged officer visits', 'Time-stamped photo evidence'].map((item) => (
                    <span key={item} className="text-ui font-extrabold text-ink">
                        {item}
                    </span>
                ))}
            </div>
        </div>
    );
}

function HowItWorks() {
    const steps = [
        ['01', 'Search the business', 'Enter the CAC (RC or BN) number. We pull the business name, status and directors from the official register.'],
        ['02', 'Choose how deep to check', 'A quick registry check, a site visit with photos, or up to 30 days of activity monitoring by our officers.'],
        ['03', 'Get a report you can share', 'Track progress on the verification page, then download a QR checkable PDF report.'],
    ];

    return (
        <section id="how" className="scroll-mt-20 bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 py-20 sm:px-6">
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <SectionHeading eyebrow="How it works" title={<>From a name to a report you can stand behind.</>} />
                    <p className="max-w-[40ch] text-ui text-muted">
                        Digital listings are easy to fake. A trained officer standing at the gate is not. Pick how deep you
                        need to go.
                    </p>
                </div>
                <div className="mt-10 grid gap-4 md:grid-cols-3">
                    {steps.map(([number, title, body]) => (
                        <div key={number} className="rounded-card bg-sunken p-6">
                            <p className="font-display text-[3rem] leading-none font-extrabold text-gold/35">{number}</p>
                            <p className="mt-4 text-body font-extrabold text-ink">{title}</p>
                            <p className="mt-1.5 text-ui text-muted">{body}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Ladder({ tier1, tier2, tier3 }: { tier1: number; tier2: number; tier3: number }) {
    const tiers: Array<{ tier: string; title: string; price: string; when: string; items: string[]; cta: string; href: string; popular?: boolean }> = [
        {
            tier: 'Tier 1',
            title: 'Registry check',
            price: priceLabel(tier1),
            when: 'Instant',
            items: ['CAC status, date and directors', 'TIN matched with FIRS', 'Name and address consistency'],
            cta: 'Run a check',
            href: '/enumerate/verify?tier=1',
        },
        {
            tier: 'Tier 2',
            title: 'Location verification',
            price: priceLabel(tier2),
            when: '24 to 48 hours',
            items: ['Everything in Tier 1', 'Officer visits the address', 'GPS fix and time-stamped photos', 'Signage and premises check'],
            cta: 'Verify a location',
            href: '/enumerate/verify?tier=2',
            popular: true,
        },
        {
            tier: 'Tier 3',
            title: 'Daily activity',
            price: priceLabel(tier3),
            when: 'Up to 30 days',
            items: ['Everything in Tier 1 and 2', 'Repeat visits in trading hours', 'Daily log of hours, staff, stock', 'Interim and final reports'],
            cta: 'Start monitoring',
            href: '/enumerate/verify?tier=3',
        },
    ];

    return (
        <section id="pricing" className="scroll-mt-20 bg-sunken">
            <div className="mx-auto max-w-[1200px] px-4 py-20 sm:px-6">
                <SectionHeading
                    align="center"
                    eyebrow="Verification tiers"
                    title="Climb the trust ladder"
                    intro="Each tier includes everything before it. Pay from your wallet; field fees are held until the visit is delivered."
                />
                <div className="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    {tiers.map((tier) => (
                        <div
                            key={tier.tier}
                            className={cx(
                                'relative flex flex-col rounded-card bg-raised p-5',
                                tier.popular === true ? 'border-2 border-gold-dark shadow-card' : 'border border-rule',
                            )}
                        >
                            {tier.popular === true && (
                                <span className="absolute -top-3 left-5 rounded-full bg-gold-dark px-2.5 py-0.5 text-[0.65rem] font-extrabold tracking-[0.06em] text-on-accent uppercase">
                                    Most popular
                                </span>
                            )}
                            <p className="text-[0.7rem] font-extrabold tracking-[0.08em] text-gold-dark uppercase">{tier.tier}</p>
                            <p className="mt-1 text-body font-extrabold text-ink">{tier.title}</p>
                            <p className="mt-3 flex items-baseline gap-2">
                                <span className="font-display text-[1.9rem] font-extrabold text-ink">{tier.price}</span>
                                <span className="text-label text-muted">{tier.when}</span>
                            </p>
                            <ul className="mt-4 flex flex-1 flex-col gap-2">
                                {tier.items.map((item) => (
                                    <li key={item} className="flex gap-2 text-ui text-ink">
                                        <Tick className="mt-0.5 text-green" />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                            <Link
                                href={tier.href}
                                className={cx(
                                    'mt-5 rounded-full py-2.5 text-center text-ui font-extrabold',
                                    tier.popular === true ? 'bg-gold-dark text-on-accent hover:bg-gold' : 'border border-rule-strong text-ink hover:border-ink',
                                )}
                            >
                                {tier.cta}
                            </Link>
                        </div>
                    ))}
                    <div className="flex flex-col rounded-card bg-[#0F1A17] p-5 text-inverse">
                        <p className="text-[0.7rem] font-extrabold tracking-[0.08em] text-logo uppercase">Organisations</p>
                        <p className="mt-1 text-body font-extrabold">Custom enumeration</p>
                        <p className="mt-3 flex items-baseline gap-2">
                            <span className="font-display text-[1.9rem] font-extrabold">Custom</span>
                            <span className="text-label text-inverse/60">Quoted per project</span>
                        </p>
                        <ul className="mt-4 flex flex-1 flex-col gap-2">
                            {['Your own data fields', 'Field teams across your area', 'Live project portal', 'Bulk checks and an account manager'].map((item) => (
                                <li key={item} className="flex gap-2 text-ui text-inverse/90">
                                    <Tick className="mt-0.5 text-logo" />
                                    {item}
                                </li>
                            ))}
                        </ul>
                        <Link href="/enumerate/organisations/new" className="mt-5 rounded-full bg-logo py-2.5 text-center text-ui font-extrabold text-ink hover:bg-white">
                            Talk to us
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    );
}

function Feature({ icon, title, body, tag }: { icon: ReactNode; title: string; body: string; tag: string }) {
    return (
        <div className="flex gap-4 rounded-card border border-rule bg-raised p-5">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-green-soft text-green">{icon}</span>
            <div>
                <p className="text-body font-extrabold text-ink">{title}</p>
                <p className="mt-1 text-ui text-muted">{body}</p>
                <span className="mt-3 inline-block rounded-sm bg-sunken px-2 py-1 text-[0.7rem] font-bold text-muted">{tag}</span>
            </div>
        </div>
    );
}

function Icon({ d }: { d: string }) {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d={d} />
        </svg>
    );
}

function WhyEnumerate() {
    return (
        <section className="bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 py-20 sm:px-6">
                <SectionHeading eyebrow="Why Enumerate" title={<>Built for credibility you can show to a bank, a board or a buyer.</>} />
                <div className="mt-10 grid gap-4 md:grid-cols-2">
                    <Feature
                        icon={<Icon d="M12 21s-6-5.6-6-11a6 6 0 1 1 12 0c0 5.4-6 11-6 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" />}
                        title="Physical proof, not just a listing"
                        body="Trained officers confirm the exact site, the signage and that the business is actually trading."
                        tag="Audit-ready evidence"
                    />
                    <Feature
                        icon={<Icon d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 19.5h.01M2 9a14 14 0 0 1 20 0" />}
                        title="Works where the network does not"
                        body="Photos, coordinates and site footprints are captured offline and sync when the officer is back in coverage."
                        tag="Offline-first field app"
                    />
                    <Feature
                        icon={<Icon d="M6 20V10M12 20V4M18 20v-7" />}
                        title="A trust ladder with timestamps"
                        body="Each badge shows when it was earned and fades when it is out of date, so you always know how fresh the evidence is."
                        tag="Re-verification reminders"
                    />
                    <Feature
                        icon={<Icon d="M7 11V7a5 5 0 0 1 10 0v4M5 11h14v10H5z" />}
                        title="Payments tied to delivery"
                        body="Field fees are held until the visit is done. If we miss the window, the money goes straight back to your wallet."
                        tag="Refund if we do not visit"
                    />
                </div>
            </div>
        </section>
    );
}

function TheReport() {
    return (
        <section className="relative overflow-hidden bg-[#0F1A17] text-inverse">
            <HexField />
            <div className="relative mx-auto grid max-w-[1200px] items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[0.9fr_1.1fr]">
                <div>
                    <SectionHeading
                        tone="dark"
                        eyebrow="The report"
                        title={<>Every verification gets its own page, and a PDF you can share.</>}
                        intro="Registry results, the officer's map pin and photos, and the daily activity log, all with dates and times. A QR code lets anyone confirm the report is genuine."
                    />
                    <Link href="/enumerate/sign-in" className="mt-6 inline-flex rounded-full bg-logo px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-white">
                        Run your first check
                    </Link>
                </div>
                <div aria-hidden="true" className="relative mx-auto w-full max-w-[520px]">
                    <div className="rotate-[-3deg] rounded-card bg-white p-5 text-ink shadow-2xl">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="text-[0.65rem] font-extrabold tracking-[0.08em] text-gold-dark uppercase">Business verification report</p>
                                <p className="mt-1 text-body font-extrabold">KORA BUILD SUPPLIES LIMITED</p>
                                <p className="text-label text-muted">RC 1482093 · Plot 14, Gwarinpa, Abuja</p>
                            </div>
                            <span className="grid size-12 grid-cols-4 gap-px rounded-[3px] bg-white p-1 ring-1 ring-ink/20">
                                {Array.from({ length: 16 }, (_, i) => (
                                    <span key={i} className={cx(([0, 1, 4, 5, 6, 9, 10, 12, 15].includes(i) ? 'bg-ink' : 'bg-white'))} />
                                ))}
                            </span>
                        </div>
                        <div className="mt-4 grid grid-cols-2 gap-3 text-label">
                            {[['Registry', 'Active, matched'], ['TIN', 'Matched with FIRS'], ['Location', '4 m from address'], ['Photos', '6 on site']].map(([k, v]) => (
                                <div key={k} className="rounded-sm bg-sunken px-2.5 py-2">
                                    <p className="text-faint">{k}</p>
                                    <p className="font-bold text-ink">{v}</p>
                                </div>
                            ))}
                        </div>
                        <div className="mt-3 h-24 rounded-sm bg-[linear-gradient(135deg,#dfe9e4,#c6dad2)]" />
                        <div className="mt-3 grid grid-cols-15 gap-0.5">
                            {Array.from({ length: 30 }, (_, i) => (
                                <span key={i} className={cx('h-3 rounded-[2px]', i % 7 === 6 ? 'bg-sunken' : 'bg-green/70')} />
                            ))}
                        </div>
                    </div>
                    <div className="absolute -top-4 -right-2 rounded-card bg-white px-4 py-3 text-ink shadow-2xl sm:-right-6">
                        <p className="font-display text-[1.8rem] leading-none font-extrabold text-gold-dark">
                            86<span className="text-ui text-muted">/100</span>
                        </p>
                        <p className="mt-1 text-label font-extrabold">Operating as described</p>
                        <p className="text-[0.7rem] text-muted">Open on 26 of 30 trading days</p>
                    </div>
                </div>
            </div>
        </section>
    );
}

function WhoItsFor() {
    const cards: Array<{ title: string; body: string; tags: string[]; dark?: boolean; art: ReactNode }> = [
        {
            title: 'Individuals',
            body: 'Hiring a contractor, paying a supplier or renting from a landlord? Check they are who they say they are before money changes hands.',
            tags: ['Contractors', 'Landlords', 'Online sellers'],
            art: <Scene tone="#E4EFE9" accent="#2F8F6B" kind="market" />,
        },
        {
            title: 'Business owners',
            body: 'Vet suppliers and partners in minutes, and get your own business verified so customers can find and trust you.',
            tags: ['Supplier checks', 'Get verified', 'Partner vetting'],
            art: <Scene tone="#F1E9DD" accent="#B7791F" kind="shop" />,
        },
        {
            title: 'Organisations',
            body: 'Run vendor onboarding, KYB and due diligence at scale, or commission a custom enumeration with your own data fields and a live project portal.',
            tags: ['Vendor onboarding', 'KYB', 'Custom datasets'],
            dark: true,
            art: <Scene tone="#1B2B26" accent="#4BB8B0" kind="grid" />,
        },
    ];

    return (
        <section className="bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 py-20 sm:px-6">
                <SectionHeading eyebrow="Who it's for" title="Know who you're dealing with" />
                <div className="mt-10 grid gap-4 md:grid-cols-3">
                    {cards.map((card) => (
                        <div key={card.title} className={cx('overflow-hidden rounded-card', card.dark === true ? 'bg-[#0F1A17] text-inverse' : 'border border-rule bg-sunken')}>
                            <div className="h-40">{card.art}</div>
                            <div className="p-5">
                                <p className="text-body font-extrabold">{card.title}</p>
                                <p className={cx('mt-1.5 text-ui', card.dark === true ? 'text-inverse/75' : 'text-muted')}>{card.body}</p>
                                <div className="mt-4 flex flex-wrap gap-1.5">
                                    {card.tags.map((tag) => (
                                        <span
                                            key={tag}
                                            className={cx('rounded-sm px-2 py-1 text-[0.7rem] font-bold', card.dark === true ? 'bg-white/10 text-inverse/85' : 'bg-raised text-muted')}
                                        >
                                            {tag}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/** A small drawn scene in place of a stock photograph. */
function Scene({ tone, accent, kind }: { tone: string; accent: string; kind: 'market' | 'shop' | 'grid' }) {
    return (
        <svg viewBox="0 0 320 160" preserveAspectRatio="xMidYMid slice" className="h-full w-full" aria-hidden="true">
            <rect width="320" height="160" fill={tone} />
            {kind === 'market' && (
                <>
                    {[30, 110, 190, 270].map((x, i) => (
                        <g key={x}>
                            <path d={`M${String(x - 30)} 70 L${String(x)} 50 L${String(x + 30)} 70 Z`} fill={i % 2 === 0 ? accent : '#D9A441'} opacity="0.85" />
                            <rect x={x - 26} y="70" width="52" height="50" fill="#fff" opacity="0.8" />
                            <rect x={x - 18} y="88" width="36" height="8" fill={accent} opacity="0.35" />
                        </g>
                    ))}
                    <rect y="120" width="320" height="40" fill={accent} opacity="0.12" />
                </>
            )}
            {kind === 'shop' && (
                <>
                    <rect x="40" y="40" width="240" height="100" fill="#fff" opacity="0.85" />
                    <rect x="40" y="40" width="240" height="22" fill={accent} opacity="0.8" />
                    <rect x="64" y="78" width="70" height="62" fill={accent} opacity="0.18" />
                    <rect x="150" y="78" width="106" height="38" fill={accent} opacity="0.12" />
                    <circle cx="250" cy="34" r="16" fill="#2F8F6B" />
                    <path d="m243 34 5 5 9-10" stroke="#fff" strokeWidth="3" fill="none" />
                </>
            )}
            {kind === 'grid' && (
                <>
                    {Array.from({ length: 7 }, (_, r) =>
                        Array.from({ length: 12 }, (_, c) => (
                            <rect
                                key={`${String(r)}-${String(c)}`}
                                x={14 + c * 25}
                                y={12 + r * 20}
                                width="20"
                                height="15"
                                rx="2"
                                fill={accent}
                                opacity={(r * 7 + c * 3) % 5 === 0 ? 0.85 : 0.18}
                            />
                        )),
                    )}
                </>
            )}
        </svg>
    );
}

function FieldNetwork({ covered }: { covered: string[] }) {
    const lit = new Set(covered);

    return (
        <section id="field" className="scroll-mt-20 bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 pb-20 sm:px-6">
                <div className="grid items-center gap-10 rounded-[24px] bg-green-soft/60 p-6 sm:p-10 lg:grid-cols-[0.9fr_1.1fr]">
                    <div className="grid w-fit grid-cols-8 gap-1.5">
                        {STATE_TILES.map((state) => (
                            <span
                                key={state.code}
                                title={state.name}
                                style={{ gridRowStart: state.row + 1, gridColumnStart: state.col + 1 }}
                                className={cx(
                                    'flex size-9 items-center justify-center rounded-[6px] text-[0.65rem] font-extrabold sm:size-11',
                                    lit.has(state.code) ? 'bg-gold-dark text-on-accent' : 'bg-raised/80 text-muted',
                                )}
                            >
                                {state.abbr}
                            </span>
                        ))}
                    </div>
                    <div>
                        <Eyebrow>Field network</Eyebrow>
                        <h2 className="mt-2 font-display text-[1.9rem] leading-[1.1] font-extrabold tracking-[-0.02em] text-ink sm:text-[2.2rem]">
                            Trained officers who go to the address, even where the signal does not.
                        </h2>
                        <p className="mt-3 max-w-[52ch] text-body text-muted">
                            Officers capture geo-tagged photos and coordinates offline and sync when they are back in
                            coverage. Supervisors check every record before it reaches you.
                        </p>
                        <dl className="mt-6 flex flex-wrap gap-x-10 gap-y-4">
                            <div>
                                <dt className="text-label text-muted">States with active ground</dt>
                                <dd className="font-display text-[2rem] font-extrabold text-ink">
                                    {STATE_TILES.filter((state) => lit.has(state.code)).length}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-label text-muted">States in scope</dt>
                                <dd className="font-display text-[2rem] font-extrabold text-ink">36 + FCT</dd>
                            </div>
                            <div>
                                <dt className="text-label text-muted">Typical site visit</dt>
                                <dd className="font-display text-[2rem] font-extrabold text-ink">24 to 48h</dd>
                            </div>
                        </dl>
                        <a href="/become-an-agent" className="mt-5 inline-block text-ui font-extrabold text-gold-dark underline underline-offset-4">
                            Become an agent
                        </a>
                    </div>
                </div>
            </div>
        </section>
    );
}

function Faq({ tier1 }: { tier1: number }) {
    const items: Array<[string, string]> = [
        [
            'What is Enumerate?',
            'Enumerate is GeoVerify\'s business search and verification platform. It helps individuals, business owners and organisations find a business and confirm it is registered, located where it says, and actively trading.',
        ],
        [
            'What do the tiers mean?',
            `Tier 1 checks the registers: CAC status and directors, and the TIN with FIRS, ${tier1 === 0 ? 'free for now' : `from ${kobo(tier1)}`}. Tier 2 adds an officer visit to the address with GPS and photos. Tier 3 adds up to 30 days of visits in trading hours with a daily log.`,
        ],
        ['Is Enumerate free to use?', 'Searching is free. You pay for a check from your wallet, and field fees are held until the visit is done.'],
        [
            'What if an officer cannot visit it in time?',
            'If we miss the visit window the field fee goes straight back to your wallet. For daily monitoring, any day nobody filed is refunded when the check closes.',
        ],
        ['Can I export the results?', 'Every check has its own page and a PDF report with a QR code anyone can scan to confirm it is genuine.'],
        [
            'Can my business be enumerated?',
            'Yes. Claim your listing in the business portal, or run a check on yourself to give customers and lenders a report they can rely on.',
        ],
        [
            'Does verification guarantee a business is trustworthy?',
            'No. It tells you what the registers say and what our officer saw, with dates. It is evidence for your decision, not a promise about future conduct.',
        ],
    ];
    const [open, setOpen] = useState(0);

    return (
        <section className="bg-raised">
            <div className="mx-auto grid max-w-[1200px] gap-10 px-4 py-20 sm:px-6 lg:grid-cols-[0.8fr_1.2fr]">
                <div>
                    <SectionHeading eyebrow="FAQ" title="Questions people ask first" intro="Cannot find your answer? Our support team replies within one working day." />
                    <Link href="/enumerate/support" className="mt-5 inline-flex rounded-full border border-rule-strong px-4 py-2 text-ui font-semibold text-ink hover:border-ink">
                        Contact support
                    </Link>
                </div>
                <ul className="flex flex-col">
                    {items.map(([question, answer], i) => (
                        <li key={question} className="border-b border-rule">
                            <button
                                type="button"
                                aria-expanded={open === i}
                                onClick={() => {
                                    setOpen(open === i ? -1 : i);
                                }}
                                className="flex min-h-touch-lg w-full items-center justify-between gap-4 py-4 text-left text-body font-extrabold text-ink"
                            >
                                {question}
                                <span
                                    aria-hidden="true"
                                    className={cx(
                                        'flex size-7 shrink-0 items-center justify-center rounded-full text-ui',
                                        open === i ? 'bg-gold-dark text-on-accent' : 'bg-sunken text-ink',
                                    )}
                                >
                                    {open === i ? '−' : '+'}
                                </span>
                            </button>
                            {open === i && <p className="pb-5 text-ui text-muted">{answer}</p>}
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

function Closing() {
    return (
        <section className="bg-raised px-4 pb-20 sm:px-6">
            <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-6 rounded-[24px] bg-gold-dark px-6 py-10 text-on-accent sm:px-10">
                <div>
                    <h2 className="font-display text-[1.8rem] font-extrabold tracking-[-0.02em]">Check a business before you trust it.</h2>
                    <p className="mt-1 text-ui opacity-85">Create a free account in minutes. Pay only for the checks you run.</p>
                </div>
                <div className="flex flex-wrap gap-3">
                    <Link href="/enumerate/sign-in" className="rounded-full bg-white px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-white/90">
                        Get started, it is free
                    </Link>
                    <Link href="/enumerate/organisations/new" className="rounded-full border border-white/60 px-5 py-2.5 text-ui font-extrabold hover:bg-white/10">
                        For organisations
                    </Link>
                </div>
            </div>
        </section>
    );
}
