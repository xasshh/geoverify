import { Head, Link } from '@inertiajs/react';
import { DirectoryChrome, EntryCard, type DirectoryEntry } from '@/components/DirectoryChrome';

interface Props {
    sector: {
        code: string;
        name: string;
        description: string | null;
        count: number;
        verified: number;
        wards: { ward: string; count: number }[];
        elsewhere: number;
    };
    results: DirectoryEntry[];
    total: number;
}

/**
 * One trade, and where in the city it is.
 *
 * The ward breakdown is short on purpose. A ward holding one business in a
 * sector names that business to anybody who reads two pages of this site, so
 * the thin ones are folded into a total rather than listed, and the page says
 * that plainly instead of appearing to be an exhaustive map.
 */
export default function Sector({ sector, results, total }: Props) {
    return (
        <DirectoryChrome>
            <Head title={sector.name} />

            <div className="border-b border-rule bg-raised">
                <div className="mx-auto max-w-6xl px-5 py-8">
                    <Link
                        href="/directory/sectors"
                        className="text-table text-muted underline underline-offset-4 hover:text-ink"
                    >
                        All sectors
                    </Link>
                    <h1 className="mt-3 max-w-[28ch] font-display text-display-l text-ink">
                        {sector.name}
                    </h1>
                    {sector.description !== null && sector.description !== '' && (
                        <p className="mt-2 max-w-[64ch] text-body text-muted">
                            {sector.description}
                        </p>
                    )}

                    <dl className="mt-6 flex flex-wrap gap-x-10 gap-y-4">
                        <div className="flex flex-col gap-0.5">
                            <dt className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                In the directory
                            </dt>
                            <dd className="numeric-mono text-display-s text-ink">{sector.count}</dd>
                        </div>
                        <div className="flex flex-col gap-0.5">
                            <dt className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                Officer verified
                            </dt>
                            <dd className="numeric-mono text-display-s text-green">
                                {sector.verified}
                            </dd>
                        </div>
                        {sector.wards.length > 0 && (
                            <div className="flex flex-col gap-1">
                                <dt className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                    Where they are
                                </dt>
                                <dd className="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-ui text-ink">
                                    {sector.wards.map((ward) => (
                                        <span key={ward.ward}>
                                            {ward.ward}{' '}
                                            <span className="numeric-mono text-table text-faint">
                                                {ward.count}
                                            </span>
                                        </span>
                                    ))}
                                    {sector.elsewhere > 0 && (
                                        <span className="text-muted">
                                            and{' '}
                                            <span className="numeric-mono text-table">
                                                {sector.elsewhere}
                                            </span>{' '}
                                            elsewhere
                                        </span>
                                    )}
                                </dd>
                            </div>
                        )}
                    </dl>
                </div>
            </div>

            <main className="mx-auto max-w-6xl px-5 py-8">
                {results.length === 0 ? (
                    <p className="text-body text-muted">
                        Nothing in this sector is published in the directory yet.
                    </p>
                ) : (
                    <>
                        <ul className="grid list-none gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {results.map((entry) => (
                                <li key={entry.id}>
                                    <EntryCard entry={entry} />
                                </li>
                            ))}
                        </ul>
                        {total > results.length && (
                            <p className="mt-6 text-ui text-muted">
                                Showing {results.length} of {total}.{' '}
                                <Link
                                    href="/directory"
                                    data={{ sector: sector.code }}
                                    className="underline underline-offset-4 hover:text-ink"
                                >
                                    See them all in the directory
                                </Link>
                                .
                            </p>
                        )}
                    </>
                )}

                <p className="mt-8 max-w-[64ch] text-label text-faint">
                    Ward counts below three are folded into "elsewhere", along with any business
                    whose ward we could not resolve. In a thinly held ward a count of one is a
                    name, and this page describes places rather than businesses. The wards listed
                    plus "elsewhere" always add up to the total above.
                </p>
            </main>
        </DirectoryChrome>
    );
}
