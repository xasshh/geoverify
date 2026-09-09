import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

interface Entry {
    id: number;
    depth: 'reduced' | 'claimed' | 'verified';
    tradingName: string;
    sector: string | null;
    sectorCode: string | null;
    structureType: string;
    ward: string | null;
    lga: string | null;
    tier: string;
    verified: boolean;
    openingHours: string | null;
}

interface Props {
    query: { q: string; sector: string | null; lga: string | null };
    results: Entry[];
    total: number;
    pageNumber: number;
    pages: number;
    sectors: { code: string; name: string; count: number }[];
    lgas: string[];
}

/**
 * The directory anybody can read.
 *
 * Written for somebody who has never heard of a register and wants to know
 * whether a shop is real. So the status of each row is stated in words on the
 * row itself rather than left to a badge somebody has to learn: a business
 * nobody has checked says so, in the same size type as the ones that have been.
 * A directory whose unverified entries look like its verified ones is selling
 * a confidence it has not earned.
 */
export default function Directory({
    query,
    results,
    total,
    pageNumber,
    pages,
    sectors,
    lgas,
}: Props) {
    const [term, setTerm] = useState(query.q);

    const go = (next: Partial<{ q: string; sector: string | null; lga: string | null }>) => {
        const merged = { ...query, ...next };
        router.get('/directory', {
            ...(merged.q !== '' && { q: merged.q }),
            ...(merged.sector !== null && { sector: merged.sector }),
            ...(merged.lga !== null && { lga: merged.lga }),
        });
    };

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title="Business directory" />

            <header className="bg-ink text-inverse">
                <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-5">
                    <Link href="/directory" className="flex items-center gap-2.5">
                        <GeoVerifyMark size={24} ink="light" />
                        <span className="font-display text-display-s">GeoVerify</span>
                        <span aria-hidden="true" className="mx-1 h-4 w-px bg-inverse/25" />
                        <span className="hidden text-label font-semibold tracking-[0.12em] text-inverse/65 uppercase sm:inline">
                            Business directory
                        </span>
                    </Link>
                    <Link href="/portal/sign-in" className="text-ui text-inverse/75 hover:text-inverse">
                        Own a business?
                    </Link>
                </div>
            </header>
            <div aria-hidden="true" className="h-[3px] bg-gold" />

            <div className="border-b border-rule bg-raised">
                <div className="mx-auto max-w-6xl px-5 py-9">
                    <h1 className="font-display text-display-l text-ink">
                        Find a business on the register
                    </h1>
                    <p className="mt-2 max-w-[62ch] text-body text-muted">
                        Every business here was either recorded by an officer at its door or added
                        by its owner. Some have been checked by an officer since. The difference is
                        stated on every entry.
                    </p>

                    <form
                        className="mt-6 flex max-w-2xl gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            go({ q: term });
                        }}
                    >
                        <input
                            type="search"
                            value={term}
                            onChange={(event) => {
                                setTerm(event.target.value);
                            }}
                            placeholder="Business name"
                            aria-label="Business name"
                            className="min-h-touch flex-grow rounded-sm border border-rule-strong bg-surface px-4 text-body text-ink placeholder:text-faint focus:border-gold focus:outline-2 focus:outline-gold"
                        />
                        <button
                            type="submit"
                            className="min-h-touch rounded-sm border border-transparent bg-gold px-6 text-ui font-semibold text-on-accent"
                        >
                            Search
                        </button>
                    </form>
                </div>
            </div>

            <main className="mx-auto flex max-w-6xl flex-col gap-8 px-5 py-8 lg:flex-row lg:items-start">
                <aside className="w-full shrink-0 lg:w-[240px]">
                    <h2 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        Sector
                    </h2>
                    <ul className="mt-3 flex list-none flex-col">
                        {sectors.map((sector) => (
                            <li key={sector.code}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        go({
                                            sector:
                                                query.sector === sector.code ? null : sector.code,
                                        });
                                    }}
                                    className={`flex min-h-touch w-full items-center gap-2 border-b border-rule py-2 text-left text-ui ${
                                        query.sector === sector.code
                                            ? 'font-semibold text-gold'
                                            : 'text-muted hover:text-ink'
                                    }`}
                                >
                                    <span className="flex-grow">{sector.name}</span>
                                    <span className="numeric-mono text-table text-faint">
                                        {sector.count}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>

                    {lgas.length > 0 && (
                        <>
                            <h2 className="mt-7 text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                Local government
                            </h2>
                            <ul className="mt-3 flex list-none flex-wrap gap-2">
                                {lgas.map((lga) => (
                                    <li key={lga}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                go({ lga: query.lga === lga ? null : lga });
                                            }}
                                            className={`min-h-touch rounded-sm border px-3 text-ui ${
                                                query.lga === lga
                                                    ? 'border-gold text-gold'
                                                    : 'border-rule-strong text-muted hover:border-ink hover:text-ink'
                                            }`}
                                        >
                                            {lga}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </aside>

                <div className="flex-grow">
                    <div className="flex flex-wrap items-baseline justify-between gap-3 border-b border-rule pb-3">
                        <p className="text-ui text-muted">
                            <span className="numeric-mono text-ink">{total.toLocaleString()}</span>{' '}
                            {total === 1 ? 'business' : 'businesses'}
                            {query.q !== '' && (
                                <>
                                    {' matching '}
                                    <span className="text-ink">{query.q}</span>
                                </>
                            )}
                        </p>
                        {(query.sector !== null || query.lga !== null || query.q !== '') && (
                            <Link href="/directory" className="text-ui text-muted underline underline-offset-4 hover:text-ink">
                                Clear filters
                            </Link>
                        )}
                    </div>

                    {results.length === 0 ? (
                        <div className="mt-8 border-l-2 border-graphite bg-raised px-5 py-4">
                            <p className="text-body text-ink">Nothing here matches that.</p>
                            <p className="mt-1 text-ui text-muted">
                                A business only appears here if it put its name on its premises or
                                its owner published it. Plenty of real businesses are on the
                                register without appearing in this directory.
                            </p>
                        </div>
                    ) : (
                        <ul className="mt-5 grid list-none gap-4 sm:grid-cols-2">
                            {results.map((entry) => (
                                <li key={entry.id}>
                                    <Link
                                        href={`/directory/${String(entry.id)}`}
                                        className="flex h-full flex-col rounded-sm border border-rule bg-surface p-5 transition-colors hover:border-rule-strong"
                                    >
                                        <DepthMark depth={entry.depth} />
                                        <h3 className="mt-2.5 font-display text-display-s text-ink">
                                            {entry.tradingName}
                                        </h3>
                                        <p className="mt-1 text-ui text-muted">
                                            {entry.sector ?? entry.structureType}
                                        </p>
                                        <p className="mt-auto pt-3 text-ui text-faint">
                                            {[entry.ward, entry.lga].filter(Boolean).join(', ') ||
                                                'Location not resolved'}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}

                    {pages > 1 && (
                        <div className="mt-8 flex items-center justify-between border-t border-rule pt-4">
                            <PageLink
                                query={query}
                                page={pageNumber - 1}
                                disabled={pageNumber <= 1}
                                label="Previous"
                            />
                            <span className="numeric-mono text-table text-faint">
                                {pageNumber} of {pages}
                            </span>
                            <PageLink
                                query={query}
                                page={pageNumber + 1}
                                disabled={pageNumber >= pages}
                                label="Next"
                            />
                        </div>
                    )}
                </div>
            </main>

            <footer className="border-t border-rule">
                <div className="mx-auto max-w-6xl px-5 py-6 text-table text-faint">
                    A register of businesses, not a licence or an endorsement. Nothing here says a
                    business is solvent, lawful or good, only what has been established about it and
                    when.
                </div>
            </footer>
        </div>
    );
}

/** What has actually been established, in words, before any colour. */
function DepthMark({ depth }: { depth: Entry['depth'] }) {
    if (depth === 'verified') {
        return (
            <span className="flex items-center gap-2 text-label font-semibold tracking-[0.12em] text-green uppercase">
                <span aria-hidden="true" className="size-2.5 rounded-full bg-green" />
                Officer verified
            </span>
        );
    }

    if (depth === 'claimed') {
        return (
            <span className="flex items-center gap-2 text-label font-semibold tracking-[0.12em] text-gold uppercase">
                <span aria-hidden="true" className="size-2.5 bg-gold" />
                Owner published
            </span>
        );
    }

    return (
        <span className="flex items-center gap-2 text-label font-semibold tracking-[0.12em] text-graphite uppercase">
            <span
                aria-hidden="true"
                className="size-2.5 rounded-full border-[1.5px] border-graphite"
            />
            Not verified
        </span>
    );
}

function PageLink({
    query,
    page,
    disabled,
    label,
}: {
    query: Props['query'];
    page: number;
    disabled: boolean;
    label: string;
}) {
    if (disabled) {
        return <span className="text-ui text-faint">{label}</span>;
    }

    return (
        <Link
            href="/directory"
            data={{
                ...(query.q !== '' && { q: query.q }),
                ...(query.sector !== null && { sector: query.sector }),
                ...(query.lga !== null && { lga: query.lga }),
                page,
            }}
            className="min-h-touch text-ui text-muted underline underline-offset-4 hover:text-ink"
        >
            {label}
        </Link>
    );
}
