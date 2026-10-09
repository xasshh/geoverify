import { useState, type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CityBackdrop } from '@/components/CityBackdrop';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { HexField } from '@/components/AuthLayouts';
import { Eyebrow, SectionHeading, SiteFooter, SiteHeader, Tick, type NavItem } from '@/components/PublicSite';
import { kobo, type Prices } from '@/lib/enumerate';
import { STATE_TILES } from '@/lib/nigeria';
import { cx } from '@/lib/cx';

const NAV: NavItem[] = [
    { label: 'Registry', href: '/directory' },
    {
        label: 'Products',
        children: [{ label: 'Enumerate', href: '/enumerate', caption: 'Verify any business in Nigeria' }],
    },
    { label: 'How it works', href: '#how' },
    { label: 'Become an agent', href: '/become-an-agent' },
];

/**
 * GeoVerify's front page.
 *
 * The reference site's story, kept: one registry where a registration and a
 * place are the same record. What changed: every door opens onto something
 * that works today, the quick check searches the real directory, and the
 * field app is shown as it looks now rather than described.
 */
export default function Home({ prices }: { prices: Prices }) {
    // The directory searches open with the business portal.
    const portalOpen = usePage().props.surfaces.portal;

    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink">
            <Head title="GeoVerify: Nigeria's registry, where business happens" />

            <Hero />
            <TwoViews />
            <Enumerate tier1={prices.tier1} />
            <BusinessPortal />
            <InvestPortal />
            {portalOpen && <QuickCheck />}
            <Coverage />
            <Steps />
            <Closing />
            <FieldAgents />
            <SiteFooter />
        </div>
    );
}

function Hero() {
    const heroSearch = usePage().props.surfaces.portal;
    const [q, setQ] = useState('');

    return (
        <section className="relative isolate min-h-[640px] overflow-hidden bg-[#0F1A17] text-inverse">
            <CityBackdrop />
            <div aria-hidden="true" className="absolute inset-0 bg-[linear-gradient(180deg,rgba(9,22,19,0.72),rgba(9,22,19,0.55)_45%,rgba(9,22,19,0.88))]" />
            <div className="relative">
                <SiteHeader
                    tone="dark"
                    brand={<GeoVerifyLockup tone="light" size={38} />}
                    links={NAV}
                    signIn={{ label: 'Sign in', href: '/portal/sign-in' }}
                />
                <div className="mx-auto max-w-[1000px] px-4 pt-20 pb-24 text-center sm:px-6 sm:pt-28">
                    <span className="inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-3 py-1.5 text-label font-bold backdrop-blur">
                        <Tick className="text-logo" /> Every pin on the map was visited by someone real
                    </span>
                    <h1 className="mt-6 font-display leading-[0.95] font-extrabold tracking-[-0.02em] uppercase">
                        <span className="block text-[2.6rem] text-logo sm:text-[4.6rem]">Nigeria's registry</span>
                        <span className="mt-1 block text-[1.6rem] font-semibold text-inverse sm:text-[2.9rem]">where business happens</span>
                    </h1>
                    <p className="mx-auto mt-6 max-w-[56ch] text-body text-inverse/80">
                        Registrations tied to GPS-confirmed, physically inspected places, so government, investors and
                        businesses all read from the same map.
                    </p>
                    {heroSearch && (
                        <form
                            className="mx-auto mt-8 flex max-w-[620px] gap-2 rounded-full bg-white p-1.5 shadow-2xl"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get('/directory', q.trim() === '' ? {} : { q: q.trim() });
                            }}
                        >
                            <input
                                aria-label="Find a business"
                                value={q}
                                onChange={(e) => {
                                    setQ(e.target.value);
                                }}
                                placeholder="Find a business: name, sector or area"
                                className="h-12 min-w-0 flex-1 rounded-full px-5 text-ui text-ink placeholder:text-faint focus:outline-none"
                            />
                            <button type="submit" className="h-12 rounded-full bg-gold-dark px-6 text-ui font-extrabold text-on-accent hover:bg-gold">
                                Locate business
                            </button>
                        </form>
                    )}
                    <div className="mt-5 flex flex-wrap justify-center gap-3">
                        <Link href="/enumerate" className="rounded-full bg-logo px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-white">
                            Verify a business
                        </Link>
                        <Link href="/become-an-agent" className="rounded-full border border-white/50 px-5 py-2.5 text-ui font-extrabold hover:bg-white/10">
                            Become an agent
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    );
}

function TwoViews() {
    return (
        <section className="bg-raised">
            <div className="mx-auto grid max-w-[1200px] items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[0.85fr_1.15fr]">
                <div className="relative mx-auto w-full max-w-[420px]">
                    <div aria-hidden="true" className="absolute -inset-4 -z-0 rounded-[32px] bg-green-soft/70" />
                    <picture>
                        <source srcSet="/media/registry-in-hand.webp" type="image/webp" />
                        <img
                            src="/media/registry-in-hand.jpg"
                            alt="A woman holding up a phone showing the GeoVerify registry"
                            width={900}
                            height={1200}
                            loading="lazy"
                            className="relative aspect-[3/4] w-full rounded-[24px] object-cover shadow-card"
                        />
                    </picture>
                    <span className="absolute bottom-5 left-5 flex items-center gap-2 rounded-full bg-raised/95 px-3 py-1.5 text-label font-extrabold text-ink shadow-card backdrop-blur">
                        <Tick className="text-green" /> The registry, in your hand
                    </span>
                </div>
                <div>
                    <SectionHeading eyebrow="One record, two views" title={<>A registered business and a verified place.</>} />
                    <p className="mt-4 text-body text-muted">
                        Most registries stop at paperwork. GeoVerify ties every registration to GPS-confirmed coordinates
                        and a physically inspected site, with the date it was seen and the officer who saw it. A listing
                        here means somebody stood at that gate.
                    </p>
                    <p className="mt-3 text-body text-muted">
                        And it is on the phone in your pocket: find a business, see where it really is, and check how
                        deep its verification goes before you pay, partner or invest.
                    </p>
                    <div className="mt-6 flex flex-wrap gap-3">
                        <Link href="/directory" className="rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold">
                            Explore the registry
                        </Link>
                        <Link href="/enumerate" className="rounded-full border border-rule-strong px-5 py-2.5 text-ui font-extrabold text-ink hover:border-ink">
                            Verify a business
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    );
}

/** A phone, playing the field app as it looks today. */
function PhoneDemo() {
    return (
        <div className="relative mx-auto w-[280px] sm:w-[300px]">
            <div className="rounded-[44px] bg-[#0b1411] p-3 shadow-2xl ring-1 ring-white/10">
                <div className="relative overflow-hidden rounded-[34px] bg-[#0F1A17]" style={{ aspectRatio: '9 / 16' }}>
                    <video
                        className="h-full w-full object-cover"
                        src="/media/field-demo.mp4"
                        poster="/media/field-demo.jpg"
                        autoPlay
                        loop
                        muted
                        playsInline
                        preload="metadata"
                        aria-label="The GeoVerify field app: an officer's day, a cell on the map, and a building captured offline"
                    />
                </div>
            </div>
        </div>
    );
}

function Enumerate({ tier1 }: { tier1: number }) {
    return (
        <section className="relative overflow-hidden bg-[#0F1A17] text-inverse">
            <HexField />
            <div className="relative mx-auto grid max-w-[1200px] items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[1.1fr_0.9fr]">
                <div>
                    <Eyebrow className="text-logo">Meet</Eyebrow>
                    <h2 className="mt-2 font-display text-[2.2rem] leading-[1.05] font-extrabold tracking-[-0.02em] sm:text-[2.8rem]">
                        GeoVerify <span className="text-logo">Enumerate</span>
                    </h2>
                    <p className="mt-4 max-w-[54ch] text-body text-inverse/80">
                        Trained field officers walk the ground with an app that works offline, capture each business with
                        photos and a GPS fix, and sync when they are back in coverage. Anyone can then check a business
                        against CAC, FIRS and what our officers saw.
                    </p>
                    <ul className="mt-6 flex flex-col gap-3">
                        {[
                            ['Verified once, trusted everywhere.', 'One registration tied to a physical, mapped location.'],
                            ['Field-confirmed addresses.', 'Officers visit and verify every listed site on the ground.'],
                            ['Evidence with dates.', 'Photos, coordinates and a supervisor review behind every record.'],
                        ].map(([lead, rest]) => (
                            <li key={lead} className="flex gap-3 text-body">
                                <Tick className="mt-1 text-logo" />
                                <span>
                                    <strong className="font-extrabold">{lead}</strong> <span className="text-inverse/75">{rest}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-7 flex flex-wrap items-center gap-4">
                        <Link href="/enumerate" className="rounded-full bg-logo px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-white">
                            Verify a business
                        </Link>
                        <span className="text-label text-inverse/60">Registry checks from {kobo(tier1)}</span>
                    </div>
                </div>
                <div>
                    <PhoneDemo />
                    <p className="mt-5 text-center font-display text-body text-inverse/70 italic">Every record carries the walk that produced it.</p>
                </div>
            </div>
        </section>
    );
}

/** A browser window holding a drawn screen, for the products without a photograph. */
function BrowserFrame({ children, dark = false }: { children: ReactNode; dark?: boolean }) {
    return (
        <div className={cx('overflow-hidden rounded-card shadow-2xl ring-1', dark ? 'bg-[#0F1A17] ring-white/10' : 'bg-raised ring-rule')}>
            <div className={cx('flex items-center gap-1.5 px-4 py-2.5', dark ? 'bg-white/5' : 'bg-sunken')}>
                {['#E5786D', '#E8B04A', '#5CB176'].map((c) => (
                    <span key={c} className="size-2.5 rounded-full" style={{ backgroundColor: c }} />
                ))}
            </div>
            {children}
        </div>
    );
}

function BusinessPortal() {
    const portalOpen = usePage().props.surfaces.portal;
    return (
        <section className="bg-sunken">
            <div className="mx-auto grid max-w-[1200px] items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[1.1fr_0.9fr]">
                <BrowserFrame>
                    <div aria-hidden="true" className="grid grid-cols-[1fr_1.2fr] gap-3 p-4">
                        <div className="flex flex-col gap-2">
                            <div className="h-9 rounded-sm bg-sunken" />
                            {[0, 1, 2, 3].map((i) => (
                                <div key={i} className={cx('rounded-sm border p-2.5', i === 0 ? 'border-gold-dark bg-green-soft/50' : 'border-rule')}>
                                    <div className="h-2.5 w-3/4 rounded bg-ink/70" />
                                    <div className="mt-1.5 h-2 w-1/2 rounded bg-faint/50" />
                                    <div className="mt-2 flex gap-1">
                                        <span className="h-3.5 w-12 rounded-sm bg-gold-dark/70" />
                                        <span className="h-3.5 w-10 rounded-sm bg-sunken" />
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="relative rounded-sm bg-[linear-gradient(135deg,#e3ece7,#cfe0d8)]">
                            {[
                                [30, 25],
                                [62, 40],
                                [45, 62],
                                [72, 72],
                                [20, 78],
                            ].map(([x, y], i) => (
                                <span
                                    key={i}
                                    className={cx('absolute size-3.5 rounded-full ring-2 ring-white', i === 0 ? 'bg-gold-dark' : 'bg-green')}
                                    style={{ left: `${String(x)}%`, top: `${String(y)}%` }}
                                />
                            ))}
                        </div>
                    </div>
                </BrowserFrame>
                <div>
                    <Eyebrow>For business owners</Eyebrow>
                    <h2 className="mt-2 font-display text-[2rem] leading-[1.1] font-extrabold tracking-[-0.02em] sm:text-[2.4rem]">
                        The business portal
                    </h2>
                    <p className="mt-4 text-body text-muted">
                        Claim the listing our officers recorded, or register a new one. Then keep it right, choose what the
                        public sees, and buy a verification visit when a lender or buyer asks for proof.
                    </p>
                    <ul className="mt-5 flex flex-col gap-3">
                        {[
                            ['Claim or register', 'Prove you control the business with a code to its phone, or add it yourself.'],
                            ['You decide what is public', 'A listing is private until you opt in, and only then can it be found.'],
                            ['Verification on demand', 'Pay for an officer visit and earn the verified badge, with its date.'],
                        ].map(([lead, rest]) => (
                            <li key={lead} className="flex gap-3 text-ui">
                                <Tick className="mt-0.5 text-green" />
                                <span>
                                    <strong className="font-extrabold text-ink">{lead}.</strong> <span className="text-muted">{rest}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-6 flex flex-wrap gap-3">
                        {portalOpen ? (
                            <Link href="/portal/register" className="rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold">
                                Register a business
                            </Link>
                        ) : (
                            <span className="rounded-full bg-sunken px-5 py-2.5 text-ui font-extrabold text-muted">Business registration opens soon</span>
                        )}
                        <Link href="/directory" className="rounded-full border border-rule-strong px-5 py-2.5 text-ui font-extrabold text-ink hover:border-ink">
                            {portalOpen ? 'Find yours to claim' : 'Explore the registry'}
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    );
}

function InvestPortal() {
    const bars: Array<[string, number]> = [
        ['Agro-processing', 28],
        ['Retail and trade', 22],
        ['Manufacturing', 16],
        ['Solid minerals', 11],
        ['Logistics', 9],
    ];

    return (
        <section className="bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 py-20 sm:px-6">
                <SectionHeading
                    eyebrow="Meet"
                    title={
                        <>
                            The <span className="text-gold-dark">Invest Portal</span>
                        </>
                    }
                    intro="The registry as a map investors can use: verified businesses and the opportunities they publish, searchable by state, sector or local government, behind a KYC check."
                />
                <div className="mt-10">
                    <BrowserFrame dark>
                        <div aria-hidden="true" className="grid gap-4 p-5 text-inverse md:grid-cols-[1.1fr_0.9fr]">
                            <div className="rounded-card bg-white/5 p-4">
                                <p className="text-label font-extrabold">Verified businesses by state</p>
                                <div className="mt-3 grid w-fit grid-cols-8 gap-1">
                                    {STATE_TILES.map((s, i) => (
                                        <span
                                            key={s.code}
                                            style={{ gridRowStart: s.row + 1, gridColumnStart: s.col + 1 }}
                                            className="flex size-7 items-center justify-center rounded-[4px] text-[0.55rem] font-extrabold"
                                        >
                                            <span
                                                className="flex size-full items-center justify-center rounded-[4px]"
                                                style={{ backgroundColor: `rgba(75,184,176,${String(0.15 + ((i * 37) % 70) / 100)})` }}
                                            >
                                                {s.abbr}
                                            </span>
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <div className="rounded-card bg-white/5 p-4">
                                <p className="text-label font-extrabold">Sectors</p>
                                <ul className="mt-3 flex flex-col gap-2.5">
                                    {bars.map(([name, pct]) => (
                                        <li key={name}>
                                            <div className="flex justify-between text-[0.7rem] text-inverse/75">
                                                <span>{name}</span>
                                                <span>{pct}%</span>
                                            </div>
                                            <div className="mt-1 h-1.5 rounded-full bg-white/10">
                                                <div className="h-full rounded-full bg-logo" style={{ width: `${String(pct * 3)}%` }} />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    </BrowserFrame>
                </div>
                <div className="mt-8 grid gap-6 md:grid-cols-3">
                    {[
                        ['Search by geography or sector', 'Filter verified businesses and opportunities by state, LGA or industry.'],
                        ['A live investment map', 'Registered businesses and investment zones as points on a real map.'],
                        ['Due diligence on the ground', 'Commission an officer visit to any business you are weighing up.'],
                    ].map(([title, body]) => (
                        <div key={title}>
                            <p className="text-body font-extrabold text-ink">{title}</p>
                            <p className="mt-1 text-ui text-muted">{body}</p>
                        </div>
                    ))}
                </div>
                <span className="mt-8 inline-flex rounded-full bg-sunken px-5 py-2.5 text-ui font-extrabold text-muted">Coming soon</span>
            </div>
        </section>
    );
}

/** The reference's "quick business check", answered by the real directory. */
function QuickCheck() {
    const [name, setName] = useState('');
    const [area, setArea] = useState('');

    return (
        <section className="bg-sunken">
            <div className="mx-auto grid max-w-[1200px] items-center gap-10 px-4 py-20 sm:px-6 lg:grid-cols-2">
                <div>
                    <h2 className="font-display text-[2.2rem] leading-[1.05] font-extrabold tracking-[-0.02em] sm:text-[2.8rem]">
                        Perform a <span className="text-gold-dark">quick</span> business check
                    </h2>
                    <p className="mt-4 max-w-[48ch] text-body text-muted">
                        See whether a business is on the registry, where it is, and how deep its verification goes. For a
                        full check against CAC and FIRS, use{' '}
                        <Link href="/enumerate" className="font-bold text-gold-dark underline underline-offset-2">
                            Enumerate
                        </Link>
                        .
                    </p>
                </div>
                <form
                    className="rounded-card border border-rule bg-raised p-6 shadow-card"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const term = [name.trim(), area.trim()].filter((part) => part !== '').join(' ');
                        router.get('/directory', term === '' ? {} : { q: term });
                    }}
                >
                    <p className="text-body font-extrabold text-ink">Search business details</p>
                    <p className="text-label text-muted">Enter a business name to see what the registry holds</p>
                    <input
                        aria-label="Business name"
                        value={name}
                        onChange={(e) => {
                            setName(e.target.value);
                        }}
                        placeholder="Business name"
                        className="mt-4 h-12 w-full rounded-sm border border-rule-strong bg-raised px-4 text-ui placeholder:text-faint focus:border-gold focus:outline-none"
                    />
                    <input
                        aria-label="Area"
                        value={area}
                        onChange={(e) => {
                            setArea(e.target.value);
                        }}
                        placeholder="Area (optional): ward, town or LGA"
                        className="mt-3 h-12 w-full rounded-sm border border-rule-strong bg-raised px-4 text-ui placeholder:text-faint focus:border-gold focus:outline-none"
                    />
                    <button type="submit" disabled={name.trim() === ''} className="mt-4 h-12 w-full rounded-sm bg-gold-dark text-ui font-extrabold text-on-accent hover:bg-gold disabled:opacity-50">
                        Locate business
                    </button>
                </form>
            </div>
        </section>
    );
}

function Coverage() {
    const facts: Array<[string, string, string]> = [
        ['Coverage', '36 + FCT', 'States in scope from the start, tiled into map cells an officer can finish in a day.'],
        ['Verification depths', '3', 'Reduced, claimed and verified: what a listing shows depends on what was proved.'],
        ['Portals', '4', 'Field, business, investor and Enumerate, each behind its own sign in.'],
        ['Field capture', 'Offline', 'Officers work without signal and sync later, with every capture scored for tampering.'],
    ];

    return (
        <section className="bg-[#F3F8F6]">
            <div className="mx-auto max-w-[1200px] px-4 py-20 sm:px-6">
                <SectionHeading
                    align="center"
                    title={<span className="text-gold-dark">Built to cover the whole country, from day one.</span>}
                    intro="GeoVerify is a new platform, and it does not claim a legacy it does not have. What it has is scoped for national coverage from the outset."
                />
                <dl className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {facts.map(([label, value, body]) => (
                        <div key={label} className="rounded-card border border-rule bg-raised p-5">
                            <dt className="text-[0.7rem] font-extrabold tracking-[0.08em] text-gold-dark uppercase">{label}</dt>
                            <dd className="mt-2 font-display text-[2.2rem] leading-none font-extrabold text-ink">{value}</dd>
                            <dd className="mt-2 text-ui text-muted">{body}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </section>
    );
}

function Steps() {
    return (
        <section id="how" className="scroll-mt-20 bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 py-20 sm:px-6">
                <SectionHeading
                    align="center"
                    title={<span className="text-gold-dark">From application to a verified point on the map</span>}
                    intro="Three steps take a business from a written application to a GPS-confirmed, publicly discoverable record."
                />
                <div className="mt-10 grid gap-4 md:grid-cols-3">
                    {[
                        ['01', 'Register', 'Create an account and submit your business details along with its physical address.'],
                        ['02', 'Verify', 'A trained field officer visits the site, confirms it exists, and captures its exact coordinates.'],
                        ['03', 'Get listed', 'Your business appears as a verified point on the national map, discoverable by buyers and investors.'],
                    ].map(([n, title, body]) => (
                        <div key={n} className="rounded-card border border-gold-dark/30 p-6">
                            <p className="font-display text-[3rem] leading-none font-extrabold text-gold-dark">{n}</p>
                            <p className="mt-4 font-display text-[1.4rem] font-extrabold text-ink">{title}</p>
                            <p className="mt-2 text-ui text-muted">{body}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Closing() {
    const portalOpen = usePage().props.surfaces.portal;
    return (
        <section className="bg-raised px-4 pb-16 sm:px-6">
            <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-6 rounded-[24px] bg-[#0E4A44] px-6 py-10 text-inverse sm:px-10">
                <div className="max-w-[56ch]">
                    <h2 className="font-display text-[1.8rem] leading-tight font-extrabold tracking-[-0.02em]">
                        Be part of Nigeria's first geo-verified business registry.
                    </h2>
                    <p className="mt-2 text-ui text-inverse/80">Register your business, or explore what has already been mapped.</p>
                </div>
                <div className="flex flex-wrap gap-3">
                    <Link href={portalOpen ? '/portal/register' : '/enumerate'} className="rounded-full bg-logo px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-white">
                        {portalOpen ? 'Get started' : 'Verify a business'}
                    </Link>
                    <Link href="/directory" className="rounded-full bg-white px-5 py-2.5 text-ui font-extrabold text-ink hover:bg-white/90">
                        Explore the registry
                    </Link>
                </div>
            </div>
        </section>
    );
}

function FieldAgents() {
    return (
        <section id="field-network" className="scroll-mt-20 bg-raised">
            <div className="mx-auto grid max-w-[1200px] items-center gap-10 px-4 pb-20 sm:px-6 lg:grid-cols-2">
                <div>
                    <Eyebrow>Field network</Eyebrow>
                    <h2 className="mt-2 font-display text-[2rem] leading-[1.1] font-extrabold tracking-[-0.02em] sm:text-[2.4rem]">
                        Become a GeoVerify field officer
                    </h2>
                    <p className="mt-4 max-w-[54ch] text-body text-muted">
                        Join the on-ground network confirming business addresses across Nigeria. Field officers are the
                        reason a GeoVerify listing means something: every pin on the map has been visited by someone real.
                    </p>
                    <Link href="/become-an-agent" className="mt-6 inline-flex rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold">
                        Become an agent
                    </Link>
                </div>
                <ul className="grid gap-3 sm:grid-cols-2">
                    {[
                        ['Clear daily work', 'A set of map cells a day, with the buildings to visit already drawn.'],
                        ['Works offline', 'The app keeps your day on the phone until there is signal.'],
                        ['Trained and supported', 'A supervisor reviews your work and messages you in the app.'],
                        ['Your own area', 'Cells near you, assigned a day at a time.'],
                    ].map(([title, body]) => (
                        <li key={title} className="rounded-card border border-rule p-4">
                            <p className="text-ui font-extrabold text-ink">{title}</p>
                            <p className="mt-1 text-label text-muted">{body}</p>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
