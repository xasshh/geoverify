import { Head, Link } from '@inertiajs/react';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';
import { Eyebrow, SiteFooter, SiteHeader, Tick, type NavItem } from '@/components/PublicSite';

const NAV: NavItem[] = [
    { label: 'Registry', href: '/directory' },
    { label: 'Products', children: [{ label: 'Enumerate', href: '/enumerate', caption: 'Verify any business in Nigeria' }] },
    { label: 'How it works', href: '/#how' },
    { label: 'Become an agent', href: '/become-an-agent' },
];

/**
 * Becoming a field agent.
 *
 * A placeholder until the application form is specified. Applying will not
 * create an account: staff never self register here, so an application is
 * reviewed and an administrator creates the agent's account on approval.
 */
export default function BecomeAgent() {
    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink">
            <Head title="Become a GeoVerify agent" />
            <SiteHeader
                brand={<GeoVerifyLockup size={36} />}
                links={NAV}
                signIn={{ label: 'Sign in', href: '/portal/sign-in' }}
                getStarted={{ label: 'Get started', href: '/portal/register' }}
            />

            <section className="mx-auto grid max-w-[1200px] items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2">
                <div>
                    <Eyebrow>Field network</Eyebrow>
                    <h1 className="mt-2 font-display text-[2.4rem] leading-[1.05] font-extrabold tracking-[-0.02em] sm:text-[3rem]">
                        Become a GeoVerify agent
                    </h1>
                    <p className="mt-4 max-w-[54ch] text-body text-muted">
                        Join the on-ground network confirming businesses, land and water across Nigeria. Agents are the
                        reason a GeoVerify record means something: every pin on the map has been visited by someone real.
                    </p>
                    <p className="mt-6 inline-flex rounded-full bg-amber-soft px-5 py-2.5 text-ui font-extrabold text-amber-ink">
                        Applications open soon
                    </p>
                    <p className="mt-3 text-label text-muted">
                        Already an agent? <Link href="/login" className="font-bold text-gold-dark underline underline-offset-2">Sign in to the field app</Link>.
                    </p>
                </div>
                <ul className="grid gap-3 sm:grid-cols-2">
                    {[
                        ['Clear daily work', 'Map cells assigned a day at a time, with what to visit already drawn.'],
                        ['Works offline', 'The app keeps your day on the phone until there is signal.'],
                        ['Trained and supported', 'A supervisor reviews your work and messages you in the app.'],
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
            </section>

            <SiteFooter />
        </div>
    );
}
