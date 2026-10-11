import { Head, Link, usePage } from '@inertiajs/react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { Eyebrow, SectionHeading, SiteFooter, SiteHeader, Tick, type NavItem } from '@/components/PublicSite';

const NAV: NavItem[] = [
    { label: 'Registry', href: '/directory' },
    { label: 'Products', children: [{ label: 'Enumerate', href: '/enumerate', caption: 'Verify any business in Nigeria' }] },
    { label: 'How it works', href: '/how-it-works' },
    { label: 'Become an agent', href: '/become-an-agent' },
];

/**
 * How GeoVerify works, for somebody who has never used anything like it.
 *
 * The steps, the closing call and the field network used to sit at the foot of
 * the home page. They live here now, with the plainest account we can give of
 * who the site is for and what each word on it means. The finer points of a
 * check (tiers, how long one stays current) stay on the directory's own page.
 */
export default function HowGeoVerifyWorks() {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink">
            <Head title="How GeoVerify works" />
            <SiteHeader brand={<GeoVerifyLockup size={36} />} links={NAV} signIn={{ label: 'Sign in', href: '/portal/sign-in' }} />

            <section className="mx-auto max-w-[1000px] px-4 pt-14 pb-6 text-center sm:px-6">
                <Eyebrow>How it works</Eyebrow>
                <h1 className="mt-2 font-display text-[2.4rem] leading-[1.05] font-extrabold tracking-[-0.02em] sm:text-[3rem]">
                    How GeoVerify works, in plain words
                </h1>
                <p className="mx-auto mt-4 max-w-[60ch] text-body text-muted">
                    Anybody can put a business name on paper. GeoVerify sends a real person to the address to check that the
                    business is truly there, then keeps a record of what they saw and when. That way, before you pay, partner
                    or lend, you know the business is real.
                </p>
            </section>

            <Paths />
            <Steps />
            <Enumerate />
            <Words />
            <FieldAgents />
            <Closing />
            <SiteFooter />
        </div>
    );
}

/** Three doors, one for each kind of visitor. */
function Paths() {
    const portalOpen = usePage().props.surfaces.portal;
    const paths: Array<{ who: string; want: string; href: string; cta: string; open: boolean }> = [
        {
            who: 'I want to check a business',
            want: 'Before you send money or sign a deal, find out if a business is registered and really at its address.',
            href: '/enumerate',
            cta: 'Verify a business',
            open: true,
        },
        {
            who: 'I own a business',
            want: 'Claim your business on the map, choose what people can see, and get a "verified" badge.',
            href: '/portal/register',
            cta: 'Register a business',
            open: portalOpen,
        },
        {
            who: 'I want to work with GeoVerify',
            want: 'Join as a field agent. You visit businesses near you and confirm they are real, using our phone app.',
            href: '/become-an-agent',
            cta: 'Become an agent',
            open: true,
        },
    ];

    return (
        <section className="mx-auto max-w-[1200px] px-4 py-10 sm:px-6">
            <h2 className="text-center font-display text-[1.6rem] font-extrabold">Start with what you want to do</h2>
            <div className="mt-6 grid gap-4 md:grid-cols-3">
                {paths.map((p) => (
                    <div key={p.who} className="flex flex-col rounded-card border border-rule bg-raised p-6 shadow-card">
                        <p className="font-display text-[1.25rem] font-extrabold text-ink">{p.who}</p>
                        <p className="mt-2 flex-1 text-ui text-muted">{p.want}</p>
                        {p.open ? (
                            <Link href={p.href} className="mt-5 inline-flex w-fit rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold">
                                {p.cta}
                            </Link>
                        ) : (
                            <span className="mt-5 inline-flex w-fit rounded-full bg-sunken px-5 py-2.5 text-ui font-extrabold text-muted">Opens soon</span>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

function Steps() {
    return (
        <section id="steps" className="scroll-mt-20 bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 py-16 sm:px-6">
                <SectionHeading
                    align="center"
                    title={<span className="text-gold-dark">From application to a verified point on the map</span>}
                    intro="Three steps take a business from a written application to a confirmed place on the map that buyers and investors can find."
                />
                <div className="mt-10 grid gap-4 md:grid-cols-3">
                    {[
                        ['01', 'Register', 'Create an account and tell us about your business, including the address where people can find it.'],
                        ['02', 'Verify', 'A trained field agent visits the address, confirms the business is there, and records its exact location on the map.'],
                        ['03', 'Get listed', 'Your business appears as a verified point on the national map, where buyers and investors can find it.'],
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

/** Checking somebody else's business, the one product open today. */
function Enumerate() {
    return (
        <section className="bg-sunken">
            <div className="mx-auto max-w-[1200px] px-4 py-16 sm:px-6">
                <SectionHeading
                    eyebrow="Checking a business"
                    title="How a check with Enumerate works"
                    intro="Enumerate is the part of GeoVerify you use to check any business in Nigeria. You need its CAC number: the RC or BN number on its certificate, invoice or letterhead."
                />
                <ol className="mt-8 grid list-none gap-4 p-0 md:grid-cols-4">
                    {[
                        ['Sign up', 'Open a free account with your email address. We send you a link to confirm it is yours.'],
                        ['Enter the CAC number', 'We look the business up on the official CAC register and show you its name, status and directors.'],
                        ['Choose how deep to check', 'A records check is instant. A site visit sends an agent to the address. Monitoring has an agent visit every trading day.'],
                        ['Get your report', 'Follow the progress on your dashboard, then download a report with a QR code anyone can scan to confirm it is genuine.'],
                    ].map(([title, body], i) => (
                        <li key={title} className="rounded-card bg-raised p-5">
                            <span className="flex size-9 items-center justify-center rounded-full bg-gold-soft text-ui font-extrabold text-gold-dark">{i + 1}</span>
                            <p className="mt-3 text-body font-extrabold text-ink">{title}</p>
                            <p className="mt-1 text-ui text-muted">{body}</p>
                        </li>
                    ))}
                </ol>
                <div className="mt-8 flex flex-wrap items-center gap-4">
                    <Link href="/enumerate" className="rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold">
                        Verify a business
                    </Link>
                    <Link href="/directory/how-verification-works" className="text-ui font-bold text-gold-dark underline underline-offset-2">
                        What each check means, in detail
                    </Link>
                </div>
            </div>
        </section>
    );
}

/** The words the site uses, said once in everyday language. */
function Words() {
    const words: Array<[string, string]> = [
        ['CAC number', 'The number the Corporate Affairs Commission gives a registered business. It starts with RC for a company or BN for a business name.'],
        ['Field agent', 'A trained person who visits a business in person and records what they find, using the GeoVerify app on their phone.'],
        ['Verified', 'A field agent went to the address, the business was there, and a supervisor checked their work. The date of the visit is always shown.'],
        ['Claimed', 'The owner proved the business is theirs and chose to show it publicly.'],
        ['Registry', 'The list of businesses GeoVerify has recorded, shown on a map. A business only appears in full once its owner agrees.'],
        ['Report', 'A document you can download and share that says what was checked, what was found and when.'],
    ];

    return (
        <section className="bg-raised">
            <div className="mx-auto max-w-[1200px] px-4 py-16 sm:px-6">
                <SectionHeading eyebrow="Words you will see" title="What our words mean" />
                <dl className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {words.map(([word, meaning]) => (
                        <div key={word} className="rounded-card border border-rule p-5">
                            <dt className="text-body font-extrabold text-ink">{word}</dt>
                            <dd className="mt-1 text-ui text-muted">{meaning}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </section>
    );
}

function FieldAgents() {
    return (
        <section id="field-network" className="scroll-mt-20 bg-sunken">
            <div className="mx-auto grid max-w-[1200px] items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2">
                <div>
                    <Eyebrow>Field network</Eyebrow>
                    <h2 className="mt-2 font-display text-[2rem] leading-[1.1] font-extrabold tracking-[-0.02em] sm:text-[2.4rem]">
                        Become a GeoVerify field officer
                    </h2>
                    <p className="mt-4 max-w-[54ch] text-body text-muted">
                        Join the people across Nigeria who confirm that businesses are where they say they are. Field
                        officers are the reason a GeoVerify listing means something: every pin on the map was visited by
                        someone real.
                    </p>
                    <Link href="/become-an-agent" className="mt-6 inline-flex rounded-full bg-gold-dark px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold">
                        Become an agent
                    </Link>
                </div>
                <ul className="grid gap-3 sm:grid-cols-2">
                    {[
                        ['Clear daily work', 'Each day you get a small area on the map, with the buildings to visit already marked.'],
                        ['Works without data', 'The app keeps your work on the phone until you have signal again.'],
                        ['Trained and supported', 'We train you first, and a supervisor checks your work and messages you in the app.'],
                        ['Near where you live', 'You work in your own area, a day at a time.'],
                    ].map(([title, body]) => (
                        <li key={title} className="flex gap-3 rounded-card border border-rule bg-raised p-4">
                            <Tick className="mt-0.5 text-green" />
                            <span>
                                <span className="block text-ui font-extrabold text-ink">{title}</span>
                                <span className="mt-1 block text-label text-muted">{body}</span>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

function Closing() {
    const portalOpen = usePage().props.surfaces.portal;
    return (
        <section className="bg-raised px-4 py-16 sm:px-6">
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
