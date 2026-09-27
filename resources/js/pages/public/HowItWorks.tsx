import { Head, Link } from '@inertiajs/react';
import { DirectoryChrome } from '@/components/DirectoryChrome';
import { TIER_MEANING, TIER_STATEMENT, TIERS } from '@/lib/tiers';

interface Props {
    currentMonths: number;
    staleMonths: number;
}

const DEPTHS = [
    {
        name: 'Seen on the street',
        body: 'A business a GeoVerify officer found with its name on a sign. We show its trading name, what it does, its ward and local government, and nothing else. It is not in search engines until its owner says so.',
    },
    {
        name: 'Claimed by its owner',
        body: 'The owner proved the business is theirs and chose to publish it. From here the page shows what the owner added: opening hours, their own photographs and their products.',
    },
    {
        name: 'Verified',
        body: 'An officer attended in person and the result was accepted on review. The page shows what was checked and the date it was checked.',
    },
] as const;

/**
 * How verification works, for somebody deciding whether to trust a listing.
 *
 * Says what each check is, how old a check can be before we say so, and what
 * this directory never publishes whoever asks. No prices: they depend on where
 * the business is and how soon it wants the visit, and a price quoted here
 * that differed from the one at checkout would be worse than none.
 */
export default function HowItWorks({ currentMonths, staleMonths }: Props) {
    return (
        <DirectoryChrome width="narrow">
            <Head title="How verification works" />
            <main className="mx-auto max-w-4xl px-5 pt-10">
                <p className="text-label font-extrabold tracking-[0.05em] text-gold uppercase">How verification works</p>
                <h1 className="mt-2 font-display text-display-l text-ink">What a GeoVerify check means, and what it does not</h1>
                <p className="mt-3 max-w-[62ch] text-body text-muted">
                    Every listing says what has been established about the business and when. A check is a fact about a
                    day an officer or our team looked, not a promise about the business today.
                </p>

                <section className="mt-10" aria-labelledby="tiers">
                    <h2 id="tiers" className="font-display text-display-s text-ink">
                        Five checks, in order
                    </h2>
                    <ol className="mt-4 flex list-none flex-col gap-3 p-0">
                        {TIERS.map((tier, i) => (
                            <li key={tier} className="flex gap-4 rounded-card border border-rule bg-raised px-5 py-4">
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-gold-soft text-ui font-extrabold text-gold-dark">
                                    {i + 1}
                                </span>
                                <span>
                                    <span className="block text-body font-extrabold text-ink">{TIER_STATEMENT[tier]}</span>
                                    <span className="block text-ui text-muted">{TIER_MEANING[tier]}</span>
                                </span>
                            </li>
                        ))}
                    </ol>
                </section>

                <section className="mt-10" aria-labelledby="age">
                    <h2 id="age" className="font-display text-display-s text-ink">
                        How old a check can be
                    </h2>
                    <p className="mt-2 max-w-[62ch] text-ui text-muted">
                        For {currentMonths} months after a check we show it as it stands. After that we show it as ageing,
                        and after {staleMonths} months as stale. It is still true that it was established; the page just
                        tells you how long ago, so you can decide what that is worth.
                    </p>
                </section>

                <section className="mt-10" aria-labelledby="depths">
                    <h2 id="depths" className="font-display text-display-s text-ink">
                        Three kinds of listing
                    </h2>
                    <div className="mt-4 grid gap-4 md:grid-cols-3">
                        {DEPTHS.map((d) => (
                            <div key={d.name} className="rounded-card border border-rule bg-raised px-5 py-5">
                                <h3 className="text-body font-extrabold text-ink">{d.name}</h3>
                                <p className="mt-2 text-ui text-muted">{d.body}</p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="mt-10 rounded-card border border-rule bg-raised px-6 py-6" aria-labelledby="never">
                    <h2 id="never" className="font-display text-display-s text-ink">
                        What we never publish
                    </h2>
                    <p className="mt-2 max-w-[62ch] text-ui text-muted">
                        The phone number or email recorded at the door, the exact position of the premises, and any
                        photograph an officer took. Being surveyed is not consent to be published. Anybody can ask for a
                        listing to be taken down from its page, without an account.
                    </p>
                </section>

                <section className="mt-10 flex flex-wrap items-center gap-4" aria-label="Next">
                    <Link
                        href="/directory"
                        className="inline-flex min-h-touch items-center rounded-sm bg-gold px-5 text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                    >
                        Explore the directory
                    </Link>
                    <Link href="/portal/sign-in" className="text-ui font-bold text-gold hover:text-gold-dark">
                        Own a business? Claim it and order a check
                    </Link>
                </section>
            </main>
        </DirectoryChrome>
    );
}
