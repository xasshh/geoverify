import { Head, Link } from '@inertiajs/react';
import { DirectoryChrome } from '@/components/DirectoryChrome';

interface Sector {
    code: string;
    name: string;
    count: number;
    verified: number;
    joinedThisMonth: number;
}

/**
 * What the directory holds, by trade.
 *
 * The page a person arrives at wanting to know what kind of business is around
 * them, and the closest thing here to the "what is active" view. The numbers
 * are counted over the directory rather than over the register, and the page
 * says so at the top rather than in a footnote: a sector where most businesses
 * have not claimed their listing looks small here and is not, and a reader who
 * is not told that will draw the wrong conclusion from a true number.
 */
export default function Sectors({
    sectors,
    unclassified,
}: {
    sectors: Sector[];
    unclassified: number;
}) {
    const largest = sectors[0]?.count ?? 1;

    return (
        <DirectoryChrome>
            <Head title="Sectors" />

            <div className="border-b border-rule bg-raised">
                <div className="mx-auto max-w-6xl px-5 py-9">
                    <h1 className="font-display text-display-l text-ink">What trade, and where</h1>
                    <p className="mt-2 max-w-[64ch] text-body text-muted">
                        Every sector the directory holds a business in, largest first. These counts
                        describe what is published here, not what exists: a business that has not
                        claimed its listing is on the register and not in these numbers.
                    </p>
                </div>
            </div>

            <main className="mx-auto max-w-6xl px-5 py-8">
                {sectors.length === 0 ? (
                    <p className="text-body text-muted">Nothing is published yet.</p>
                ) : (
                    <ul className="flex list-none flex-col">
                        {sectors.map((sector) => (
                            <li key={sector.code}>
                                <Link
                                    href={`/directory/sectors/${sector.code}`}
                                    className="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-rule py-4 hover:bg-raised"
                                >
                                    <span className="flex min-w-[16rem] flex-grow flex-col gap-1">
                                        <span className="text-body text-ink">{sector.name}</span>
                                        <span className="flex items-center gap-3">
                                            <span
                                                aria-hidden="true"
                                                className="h-1.5 max-w-[14rem] flex-grow bg-sunken"
                                            >
                                                <span
                                                    className="block h-1.5 bg-gold"
                                                    style={{
                                                        width: `${String(Math.max(4, Math.round((sector.count / largest) * 100)))}%`,
                                                    }}
                                                />
                                            </span>
                                            <span className="numeric-mono text-table text-faint">
                                                {sector.count}
                                            </span>
                                        </span>
                                    </span>

                                    <span className="flex flex-col gap-0.5 text-right">
                                        <span className="numeric-mono text-ui text-green">
                                            {sector.verified}
                                        </span>
                                        <span className="text-label text-faint">
                                            officer verified
                                        </span>
                                    </span>

                                    <span className="flex w-[9rem] flex-col gap-0.5 text-right">
                                        <span className="numeric-mono text-ui text-ink">
                                            {sector.joinedThisMonth === 0
                                                ? '0'
                                                : `+${String(sector.joinedThisMonth)}`}
                                        </span>
                                        <span className="text-label text-faint">this month</span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {unclassified > 0 && (
                    <p className="mt-5 text-ui text-muted">
                        <span className="numeric-mono text-ink">{unclassified}</span>{' '}
                        {unclassified === 1 ? 'business is' : 'businesses are'} in the directory
                        with no recorded trade, so {unclassified === 1 ? 'it appears' : 'they appear'}{' '}
                        under none of these headings.{' '}
                        <Link
                            href="/directory"
                            className="underline underline-offset-4 hover:text-ink"
                        >
                            They are in the directory
                        </Link>
                        .
                    </p>
                )}

                <p className="mt-6 max-w-[64ch] text-label text-faint">
                    "This month" counts listings that became visible here since the first of the
                    month. It is not a measure of trading, of growth, or of how a sector is doing.
                </p>
            </main>
        </DirectoryChrome>
    );
}
