import { Head, Link } from '@inertiajs/react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { PeoplePhoto } from '@/components/AuthLayouts';
import { Eyebrow, SectionHeading, SiteFooter, SiteHeader, Tick, type NavItem } from '@/components/PublicSite';

const NAV: NavItem[] = [
    { label: 'Registry', href: '/directory' },
    { label: 'Products', children: [{ label: 'Enumerate', href: '/enumerate', caption: 'Verify any business in Nigeria' }] },
    { label: 'How it works', href: '/how-it-works' },
    { label: 'Become an agent', href: '/become-an-agent' },
];

const APPLY = '/enumerate/agent';

/**
 * Becoming a field agent, for somebody deciding whether to apply.
 *
 * Applying happens inside Enumerate on an ordinary portal account: one sign up,
 * one email to prove. That account never becomes staff by itself. An
 * administrator approving the application is what creates the officer.
 */
export default function BecomeAgent() {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink">
            <Head title="Become a GeoVerify agent" />
            <SiteHeader brand={<GeoVerifyLockup size={36} />} links={NAV} signIn={{ label: 'Sign in', href: '/enumerate/sign-in' }} />

            <section className="mx-auto grid max-w-[1200px] items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2">
                <div>
                    <Eyebrow>Field network</Eyebrow>
                    <h1 className="mt-2 font-display text-[2.4rem] leading-[1.05] font-extrabold tracking-[-0.02em] sm:text-[3rem]">
                        Become a GeoVerify agent
                    </h1>
                    <p className="mt-4 max-w-[54ch] text-body text-muted">
                        Field agents visit businesses and places near where they live and record what they find, using the
                        GeoVerify app on their phone. It is the reason a GeoVerify record can be trusted: every pin on the
                        map was visited by someone real.
                    </p>
                    <div className="mt-6 flex flex-wrap items-center gap-3">
                        <Link href={APPLY} className="rounded-full bg-gold-dark px-6 py-3 text-ui font-extrabold text-on-accent hover:bg-gold">
                            Apply now
                        </Link>
                        <span className="text-label text-muted">Free to apply. Takes about 20 minutes.</span>
                    </div>
                    <p className="mt-4 text-label text-muted">
                        Already an agent?{' '}
                        <Link href="/login" className="font-bold text-gold-dark underline underline-offset-2">
                            Sign in to the agent app
                        </Link>
                        .
                    </p>
                </div>
                <PeoplePhoto
                    name="people-agent-market"
                    alt="A GeoVerify field agent in a teal vest checking her phone on a bench outside a market"
                    caption="Agents work in their own area, a day at a time"
                    className="mx-auto h-[460px] w-full max-w-[440px]"
                />
            </section>

            <ul className="mx-auto grid max-w-[1200px] gap-3 px-4 pb-16 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
                {[
                    ['Clear daily work', 'Each day you get a small area on the map, with the places to visit already marked.'],
                    ['Works without data', 'The app keeps your work on the phone until you have signal again.'],
                    ['Trained and supported', 'We train you first. A supervisor checks your work and messages you in the app.'],
                    ['Near where you live', 'Work in your own area, in the languages you speak.'],
                ].map(([title, body]) => (
                    <li key={title} className="flex gap-3 rounded-card border border-rule p-4">
                        <Tick className="mt-0.5 text-green" />
                        <span>
                            <span className="block text-ui font-extrabold">{title}</span>
                            <span className="mt-1 block text-label text-muted">{body}</span>
                        </span>
                    </li>
                ))}
            </ul>

            <section className="bg-sunken">
                <div className="mx-auto max-w-[1200px] px-4 py-16 sm:px-6">
                    <SectionHeading align="center" eyebrow="How to apply" title="Four steps from applying to your first day" />
                    <ol className="mt-10 grid list-none gap-4 p-0 md:grid-cols-4">
                        {[
                            ['Open a free account', 'Sign up with your email address and confirm it from the link we send you. This is the same account used to check businesses on Enumerate.'],
                            ['Fill in the application', 'Four short parts: about you, background checks, a quick test of your phone, and the agreements. Save as you go and come back any time.'],
                            ['We check your details', 'Our team looks at your application, usually within a week, and emails you. If something is missing, we tell you exactly what.'],
                            ['Training, then work', 'We train you on the app and on visiting businesses safely, with a short test. Then you get your agent sign in and your first area.'],
                        ].map(([title, body], i) => (
                            <li key={title} className="rounded-card bg-raised p-5">
                                <span className="flex size-9 items-center justify-center rounded-full bg-gold-soft text-ui font-extrabold text-gold-dark">{i + 1}</span>
                                <p className="mt-3 text-body font-extrabold text-ink">{title}</p>
                                <p className="mt-1 text-ui text-muted">{body}</p>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            <section className="mx-auto grid max-w-[1200px] gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2">
                <div>
                    <SectionHeading eyebrow="Before you start" title="What you will need" />
                    <ul className="mt-6 flex flex-col gap-3">
                        {[
                            ['To be 18 or older', 'and living in Nigeria.'],
                            ['Your NIN', 'We check it matches your name. We never keep the full number.'],
                            ['A government ID', 'NIN slip, voter’s card, driver’s licence or international passport.'],
                            ['Your highest certificate', 'SSCE is the minimum.'],
                            ['A guarantor', 'Someone who knows you well, is not a relative, and will sign a form for you. You can print the form from your application.'],
                            ['Someone we can call in an emergency', 'Your next of kin.'],
                            ['A smartphone', 'Android or iPhone, with a working camera and location. A power bank helps on long days.'],
                        ].map(([lead, rest]) => (
                            <li key={lead} className="flex gap-3 text-ui">
                                <Tick className="mt-0.5 text-green" />
                                <span>
                                    <strong className="font-extrabold text-ink">{lead}.</strong> <span className="text-muted">{rest}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
                <div>
                    <SectionHeading eyebrow="Your information" title="How we look after it" />
                    <div className="mt-6 flex flex-col gap-4 text-ui text-muted">
                        <p>
                            Only the few staff who review applications can see your documents, and they are stored encrypted. We
                            keep only the last four digits of your NIN, never the whole number.
                        </p>
                        <p>
                            Once you are working, the app records your location during your working hours only, so supervisors
                            can see the work was done. Never outside them.
                        </p>
                        <p>We handle all of it under the Nigeria Data Protection Act 2023, and you agree to each part separately before you send your application.</p>
                    </div>
                    <Link href={APPLY} className="mt-8 inline-flex rounded-full bg-gold-dark px-6 py-3 text-ui font-extrabold text-on-accent hover:bg-gold">
                        Start my application
                    </Link>
                </div>
            </section>

            <SiteFooter />
        </div>
    );
}
