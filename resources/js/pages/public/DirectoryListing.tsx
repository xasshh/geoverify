import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

interface Listing {
    id: number;
    depth: 'reduced' | 'claimed' | 'verified';
    tradingName: string;
    sector: string | null;
    structureType: string;
    ward: string | null;
    lga: string | null;
    tier: string;
    verified: boolean;
    openingHours: string | null;
}

/**
 * One business, to a stranger.
 *
 * The page says what is known and then says, in the same weight, what is not.
 * A directory entry that lists four facts and stays quiet about the fifth
 * invites the reader to assume the fifth: that somebody checked. Most of these
 * businesses have not been checked, and the page's first job is to be honest
 * about that before it is useful about anything else.
 *
 * There is no phone number here, no email, no photograph and no coordinate, at
 * any depth. Those are in the register and they stay there.
 */
export default function DirectoryListing({ listing }: { listing: Listing }) {
    const flash = usePage().props.flash.status;
    const [asking, setAsking] = useState(false);
    const [reason, setReason] = useState('');

    const place = [listing.ward, listing.lga].filter(Boolean).join(', ');

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title={listing.tradingName} />

            <header className="bg-ink text-inverse">
                <div className="mx-auto flex h-14 max-w-4xl items-center justify-between gap-4 px-5">
                    <Link href="/directory" className="flex items-center gap-2.5">
                        <GeoVerifyMark size={24} ink="light" />
                        <span className="font-display text-display-s">GeoVerify</span>
                    </Link>
                    <Link href="/portal/sign-in" className="text-ui text-inverse/75 hover:text-inverse">
                        Own this business?
                    </Link>
                </div>
            </header>
            <div aria-hidden="true" className="h-[3px] bg-gold" />

            <main className="mx-auto max-w-4xl px-5 py-8">
                <Link href="/directory" className="text-table text-muted underline underline-offset-4 hover:text-ink">
                    Directory
                </Link>

                {flash !== null && flash !== '' && (
                    <p className="mt-4 border-l-2 border-green bg-raised px-4 py-3 text-ui text-ink">
                        {flash}
                    </p>
                )}

                <h1 className="mt-4 font-display text-display-xl text-ink">{listing.tradingName}</h1>
                <p className="mt-2 text-body text-muted">
                    {listing.sector ?? listing.structureType}
                    {place !== '' && ` · ${place}`}
                </p>

                <section
                    className={`mt-7 border-l-2 px-5 py-4 ${
                        listing.depth === 'verified'
                            ? 'border-green bg-raised'
                            : listing.depth === 'claimed'
                              ? 'border-gold bg-raised'
                              : 'border-graphite bg-sunken'
                    }`}
                    aria-labelledby="standing"
                >
                    <h2 id="standing" className="font-display text-display-s text-ink">
                        {listing.depth === 'verified' && 'An officer has been here'}
                        {listing.depth === 'claimed' && 'The owner published this listing'}
                        {listing.depth === 'reduced' && 'Nobody has verified this business'}
                    </h2>
                    <p className="mt-2 max-w-[62ch] text-ui text-muted">
                        {listing.depth === 'verified' &&
                            'An officer of this register attended the address, took a satellite position on the spot and recorded what they found. What that establishes is stated below.'}
                        {listing.depth === 'claimed' &&
                            'Somebody proved they control this business and chose to publish it here. Nobody from this register has visited to check what they said.'}
                        {listing.depth === 'reduced' &&
                            'This business appears here because its name is on its premises and an officer recorded it while surveying the area. It has not been claimed by its owner and nobody has verified it.'}
                    </p>
                </section>

                <dl className="mt-8 grid gap-x-8 sm:grid-cols-2">
                    <Fact term="What it does">{listing.sector ?? 'Not recorded'}</Fact>
                    <Fact term="Premises">{listing.structureType}</Fact>
                    <Fact term="Ward">{listing.ward ?? 'Not resolved'}</Fact>
                    <Fact term="Local government">{listing.lga ?? 'Not resolved'}</Fact>
                    {listing.openingHours !== null && (
                        <Fact term="Opening hours">{listing.openingHours}</Fact>
                    )}
                </dl>

                <section className="mt-9 border-t border-rule pt-6" aria-labelledby="not-shown">
                    <h2
                        id="not-shown"
                        className="text-label font-semibold tracking-[0.12em] text-muted uppercase"
                    >
                        What this page does not show
                    </h2>
                    <p className="mt-2 max-w-[62ch] text-ui text-muted">
                        Not the phone number or email recorded at the door, not the exact
                        coordinate, and no photograph. Those belong to the business, and being
                        surveyed is not consent to publish them.
                    </p>
                </section>

                <section className="mt-8 rounded-sm border border-rule bg-raised px-5 py-5" aria-labelledby="owner">
                    <h2 id="owner" className="font-display text-display-s text-ink">
                        Is this your business?
                    </h2>
                    <p className="mt-2 max-w-[62ch] text-ui text-muted">
                        Claim it and you decide what appears here. You can also ask for it to be
                        taken down, and you do not have to claim it first to do that.
                    </p>
                    <div className="mt-4 flex flex-wrap items-center gap-4">
                        <Link
                            href="/portal/sign-in"
                            className="inline-flex min-h-touch items-center rounded-sm border border-transparent bg-gold px-5 text-ui font-semibold text-on-accent"
                        >
                            Claim this listing
                        </Link>
                        <button
                            type="button"
                            onClick={() => {
                                setAsking((open) => !open);
                            }}
                            className="min-h-touch text-ui text-muted underline underline-offset-4 hover:text-ink"
                        >
                            Ask for it to be removed
                        </button>
                    </div>

                    {asking && (
                        <form
                            className="mt-5 border-t border-rule pt-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.post(`/directory/${String(listing.id)}/remove`, { reason });
                            }}
                        >
                            <label
                                htmlFor="reason"
                                className="text-label font-semibold tracking-[0.12em] text-muted uppercase"
                            >
                                Why, if you want to say
                            </label>
                            <textarea
                                id="reason"
                                value={reason}
                                onChange={(event) => {
                                    setReason(event.target.value);
                                }}
                                rows={3}
                                className="mt-2 w-full rounded-sm border border-rule-strong bg-surface p-3 text-ui text-ink focus:border-gold focus:outline-2 focus:outline-gold"
                            />
                            <p className="mt-2 text-table text-faint">
                                This takes the listing out of the directory. It does not delete the
                                business from the register, and the owner can publish it again by
                                claiming it.
                            </p>
                            <button
                                type="submit"
                                className="mt-3 min-h-touch rounded-sm border border-rule-strong px-5 text-ui font-medium text-ink hover:border-ink"
                            >
                                Take it down
                            </button>
                        </form>
                    )}
                </section>
            </main>

            <footer className="border-t border-rule">
                <div className="mx-auto max-w-4xl px-5 py-6 text-table text-faint">
                    A register of businesses, not a licence or an endorsement.
                </div>
            </footer>
        </div>
    );
}

function Fact({ term, children }: { term: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5 border-b border-rule py-3">
            <dt className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                {term}
            </dt>
            <dd className="text-ui text-ink">{children}</dd>
        </div>
    );
}
