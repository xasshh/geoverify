import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { DirectoryChrome, DepthMark, type DirectoryEntry as Entry } from '@/components/DirectoryChrome';
import { DirectoryMap, type DirectoryMapData } from '@/components/DirectoryMap';
import { cx } from '@/lib/cx';
import { directionsUrl } from '@/lib/directions';

interface Query {
    q: string;
    sector: string | null;
    lga: string | null;
    where: string | null;
    verified: boolean;
    photos: boolean;
    products: boolean;
    sort: string;
    open: boolean;
    pays: boolean;
    inspection: boolean;
    delivers: boolean;
    near: string | null;
    box: string | null;
}

interface Props {
    query: Query;
    results: Entry[];
    total: number;
    pageNumber: number;
    pages: number;
    sectors: { code: string; name: string; count: number }[];
    lgas: string[];
    meaning: { code: string; name: string }[];
    map: DirectoryMapData;
    places: { ward: string; lga: string | null }[];
    inspectionOffered: boolean;
    saved: number[];
}

const TINTS = ['bg-[#E3F2EF]', 'bg-[#E8EEFD]', 'bg-[#FBF1E0]', 'bg-[#EEEAF7]', 'bg-[#F4E6E4]'];

/**
 * The public marketplace, as the Search & Map board draws it: what and where,
 * verified only, the popular trades, the list, and the map beside it.
 *
 * Every row still states its depth in words. The map shows published
 * businesses as their cell and everything else only as density, because an
 * unclaimed business has published its name and ward and nothing else.
 * "Nearest first" measures to a published business's cell centre, from the
 * ward searched or the reader's own position, and unclaimed rows follow.
 */
export default function Directory({ query, results, total, pageNumber, pages, sectors, meaning, map, places, inspectionOffered, saved }: Props) {
    const [what, setWhat] = useState(query.q);
    const [where, setWhere] = useState(query.where ?? '');
    const [verified, setVerified] = useState(query.verified);
    const [focus, setFocus] = useState<number | null>(null);

    const cells = useMemo(() => new Map(map.pins.map((p) => [p.id, p])), [map.pins]);

    const go = (next: Partial<Query> & { page?: number }) => {
        const merged = { ...query, ...next };
        router.get(
            '/directory',
            {
                ...(merged.q !== '' && { q: merged.q }),
                ...(merged.sector !== null && { sector: merged.sector }),
                ...(merged.lga !== null && { lga: merged.lga }),
                ...(merged.where !== null && merged.where !== '' && { where: merged.where }),
                ...(merged.verified && { verified: 1 }),
                ...(merged.photos && { photos: 1 }),
                ...(merged.products && { products: 1 }),
                ...(merged.sort !== 'relevance' && { sort: merged.sort }),
                ...(merged.open && { open: 1 }),
                ...(merged.pays && { pays: 1 }),
                ...(merged.inspection && { inspection: 1 }),
                ...(merged.delivers && { delivers: 1 }),
                ...(merged.near !== null && { near: merged.near }),
                ...(merged.box !== null && { box: merged.box }),
                ...(next.page !== undefined && next.page > 1 && { page: next.page }),
            },
            { preserveScroll: true },
        );
    };

    const placeName = query.where ?? query.lga;
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [locating, setLocating] = useState(false);
    const canNearest = query.where !== null || query.near !== null;

    // The reader's position, asked for once and used for this search only.
    const nearMe = () => {
        if (!('geolocation' in navigator)) {
            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (p) => {
                setLocating(false);
                go({ near: `${p.coords.latitude.toFixed(4)},${p.coords.longitude.toFixed(4)}`, sort: 'nearest', page: 1 });
            },
            () => {
                setLocating(false);
            },
            { timeout: 10_000, maximumAge: 60_000 },
        );
    };

    return (
        <DirectoryChrome width="full">
            <Head title="Find and verify any business in Nigeria" />

            <section className="border-b border-rule bg-raised">
                <div className="mx-auto max-w-[1440px] px-4 pt-4 pb-4 sm:px-5 sm:pt-10 sm:pb-8 lg:px-10">
                    {/* Phone: straight to the search and the map, as the phone board. */}
                    <h1 className="sr-only sm:hidden">Find, locate and verify any business in Nigeria.</h1>
                    <div className="hidden flex-wrap items-end justify-between gap-6 sm:flex">
                        <div>
                            <h1 className="font-display text-display-xl text-ink">Find, locate and verify any business in Nigeria.</h1>
                            <p className="mt-2 max-w-[78ch] text-body text-muted">
                                Every listing is on the register, recorded on site by a GeoVerify field agent or added by its owner. Each one
                                says plainly what has been verified about it.
                            </p>
                        </div>
                        <span className="flex items-center gap-2 rounded-sm bg-gold-soft px-4 py-2.5 text-ui font-bold text-gold-dark">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5 4.5-5" />
                            </svg>
                            Search is free for everyone
                        </span>
                    </div>

                    <form
                        className="grid overflow-hidden rounded-card border border-rule-strong bg-raised shadow-card sm:mt-6 md:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_auto]"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ q: what.trim(), where: where === '' ? null : where, verified, page: 1 });
                        }}
                    >
                        <label className="flex items-center gap-4 border-b border-rule px-6 py-3.5 md:border-r md:border-b-0">
                            <svg className="shrink-0 text-muted" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" />
                                <path d="M20.5 20.5 16 16" />
                            </svg>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="text-label font-bold tracking-[0.05em] text-muted uppercase">What</span>
                                <input
                                    value={what}
                                    onChange={(e) => {
                                        setWhat(e.target.value);
                                    }}
                                    placeholder="A business name or a trade, like pharmacy"
                                    className="min-w-0 bg-transparent text-body font-semibold text-ink placeholder:font-normal placeholder:text-faint focus:outline-none"
                                />
                            </span>
                        </label>
                        <label className="flex items-center gap-4 border-b border-rule px-6 py-3.5 md:border-r md:border-b-0">
                            <svg className="shrink-0 text-muted" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                <path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z" />
                                <circle cx="12" cy="10" r="2.5" />
                            </svg>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="text-label font-bold tracking-[0.05em] text-muted uppercase">Where</span>
                                <select
                                    value={where}
                                    onChange={(e) => {
                                        setWhere(e.target.value);
                                    }}
                                    className="min-w-0 bg-transparent text-body font-semibold text-ink focus:outline-none"
                                >
                                    <option value="">Anywhere</option>
                                    {places.map((p) => (
                                        <option key={`${p.ward}-${p.lga ?? ''}`} value={p.ward}>
                                            {p.ward}
                                            {p.lga !== null && `, ${p.lga}`}
                                        </option>
                                    ))}
                                </select>
                            </span>
                        </label>
                        <div className="flex items-center gap-5 px-5 py-3">
                            <label className="flex items-center gap-2.5 text-ui font-semibold whitespace-nowrap text-ink">
                                <input
                                    type="checkbox"
                                    checked={verified}
                                    onChange={(e) => {
                                        setVerified(e.target.checked);
                                    }}
                                    className="size-5 accent-gold"
                                />
                                Verified only
                            </label>
                            <button type="submit" className="min-h-[52px] rounded-sm bg-gold px-8 text-body font-extrabold text-on-accent hover:bg-gold-dark">
                                Search
                            </button>
                        </div>
                    </form>

                    {sectors.length > 0 && (
                        <div className="mt-5 hidden flex-wrap items-center gap-2.5 sm:flex">
                            <span className="text-ui text-muted">Popular:</span>
                            {sectors.slice(0, 6).map((s) => (
                                <button
                                    key={s.code}
                                    type="button"
                                    onClick={() => {
                                        go({ sector: query.sector === s.code ? null : s.code, page: 1 });
                                    }}
                                    className={cx(
                                        'max-w-[260px] truncate rounded-full px-4 py-2 text-ui font-semibold',
                                        query.sector === s.code ? 'bg-gold text-on-accent' : 'bg-sunken text-ink hover:bg-rule',
                                    )}
                                    title={s.name}
                                >
                                    {shortSector(s.name)}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </section>

            <div className="mx-auto grid max-w-[1440px] gap-4 px-4 py-4 sm:gap-6 sm:px-5 sm:py-8 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1fr)] lg:px-10">
                <section aria-label="Results" className="min-w-0">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-body text-muted">
                            <span className="font-extrabold text-ink">
                                {total.toLocaleString('en-NG')} {query.verified ? 'verified ' : ''}
                                {total === 1 ? 'business' : 'businesses'}
                            </span>
                            {query.near !== null ? ' near you' : placeName !== null && ` in ${placeName}`}
                            {query.box !== null && ' on the map'}
                        </p>
                        <label className="flex items-center gap-2 text-ui text-muted">
                            Sort
                            <select
                                value={query.sort}
                                onChange={(e) => {
                                    go({ sort: e.target.value, page: 1 });
                                }}
                                className="h-10 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-bold text-ink"
                            >
                                <option value="relevance">Verified first</option>
                                {canNearest && <option value="nearest">Nearest first</option>}
                                <option value="name">Name, A to Z</option>
                                <option value="newest">Newest on the register</option>
                            </select>
                        </label>
                    </div>

                    <div className="mt-4 flex gap-2 overflow-x-auto pb-1 lg:flex-wrap lg:overflow-visible">
                        <Chip active={query.open} onClick={() => { go({ open: !query.open, page: 1 }); }}>
                            Open now
                        </Chip>
                        <Chip active={query.pays} onClick={() => { go({ pays: !query.pays, page: 1 }); }}>
                            Pays via GeoVerify
                        </Chip>
                        {inspectionOffered && (
                            <Chip active={query.inspection} onClick={() => { go({ inspection: !query.inspection, page: 1 }); }}>
                                Inspection available
                            </Chip>
                        )}
                        <Chip active={query.delivers} onClick={() => { go({ delivers: !query.delivers, page: 1 }); }}>
                            Delivers
                        </Chip>
                        <Chip active={filtersOpen || query.photos || query.products} onClick={() => { setFiltersOpen((o) => !o); }}>
                            Filters
                        </Chip>
                        <Chip active={query.near !== null} onClick={nearMe}>
                            {locating ? 'Finding you…' : 'Near me'}
                        </Chip>
                        {(query.sector !== null || query.where !== null || query.lga !== null || query.q !== '' || query.near !== null || query.box !== null) && (
                            <Chip
                                active={false}
                                onClick={() => {
                                    router.get('/directory');
                                }}
                            >
                                Clear all
                            </Chip>
                        )}
                    </div>
                    {filtersOpen && (
                        <div className="mt-3 flex flex-wrap gap-2 rounded-card border border-rule bg-raised p-3">
                            <Chip
                                active={query.verified}
                                onClick={() => {
                                    setVerified(!query.verified);
                                    go({ verified: !query.verified, page: 1 });
                                }}
                            >
                                Verified only
                            </Chip>
                            <Chip active={query.photos} onClick={() => { go({ photos: !query.photos, page: 1 }); }}>
                                Has photos
                            </Chip>
                            <Chip active={query.products} onClick={() => { go({ products: !query.products, page: 1 }); }}>
                                Lists products
                            </Chip>
                        </div>
                    )}

                    {meaning.length > 0 && (
                        <p className="mt-5 rounded-sm bg-held-soft px-4 py-3 text-ui text-held-ink">
                            Nothing by that name. Did you mean{' '}
                            {meaning.map((m, i) => (
                                <span key={m.code}>
                                    {i > 0 && ' or '}
                                    <button type="button" className="font-extrabold underline underline-offset-4" onClick={() => { go({ q: '', sector: m.code, page: 1 }); }}>
                                        {m.name}
                                    </button>
                                </span>
                            ))}
                            ?
                        </p>
                    )}

                    <ul className="mt-5 flex list-none flex-col gap-4">
                        {results.map((entry, i) => (
                            <ResultCard
                                key={entry.id}
                                entry={entry}
                                tint={TINTS[i % TINTS.length] ?? ''}
                                cell={cells.get(entry.id)?.cell ?? null}
                                active={focus === entry.id}
                                saved={saved.includes(entry.id)}
                                onShow={
                                    cells.has(entry.id)
                                        ? () => {
                                              setFocus(entry.id);
                                          }
                                        : null
                                }
                            />
                        ))}
                    </ul>

                    {results.length === 0 && meaning.length === 0 && (
                        <p className="mt-5 rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                            Nothing matches. Try another name or trade, or search anywhere.
                        </p>
                    )}

                    {pages > 1 && (
                        <nav className="mt-6 flex items-center justify-between" aria-label="Pages">
                            <button
                                type="button"
                                disabled={pageNumber <= 1}
                                onClick={() => {
                                    go({ page: pageNumber - 1 });
                                }}
                                className="min-h-touch rounded-sm border border-rule-strong bg-raised px-4 text-ui font-bold text-ink disabled:opacity-40"
                            >
                                Previous
                            </button>
                            <span className="text-ui text-muted">
                                Page {pageNumber} of {pages}
                            </span>
                            <button
                                type="button"
                                disabled={pageNumber >= pages}
                                onClick={() => {
                                    go({ page: pageNumber + 1 });
                                }}
                                className="min-h-touch rounded-sm border border-rule-strong bg-raised px-4 text-ui font-bold text-ink disabled:opacity-40"
                            >
                                Next
                            </button>
                        </nav>
                    )}
                </section>

                {/* Phone: the map first, the list under it, as the phone board. */}
                <div className="order-first h-[46dvh] lg:sticky lg:top-6 lg:order-none lg:h-[calc(100dvh-3rem)]">
                    <DirectoryMap
                        data={map}
                        focus={focus}
                        onSelect={setFocus}
                        boxActive={query.box !== null}
                        onBox={(box) => {
                            go({ box, page: 1 });
                        }}
                    />
                </div>
            </div>
        </DirectoryChrome>
    );
}

/** "Retail sale in non-specialized stores with food..." is a taxonomy, not a chip. */
function shortSector(name: string): string {
    const first = name.split(/[;,(]/)[0]?.trim() ?? name;

    return first.length > 34 ? `${first.slice(0, 32).trimEnd()}…` : first;
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cx(
                'min-h-[44px] rounded-full border px-4 text-ui font-semibold',
                active ? 'border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong bg-raised text-ink hover:bg-sunken',
            )}
        >
            {children}
        </button>
    );
}

function ResultCard({
    entry,
    tint,
    cell,
    active,
    saved,
    onShow,
}: {
    entry: Entry;
    tint: string;
    cell: string | null;
    active: boolean;
    saved: boolean;
    onShow: (() => void) | null;
}) {
    const signedIn = usePage().props.auth.portal !== null;
    const photo = entry.photos[0];
    const published = entry.depth !== 'reduced';
    const initials = entry.tradingName
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <li
            className={cx(
                'flex gap-4 rounded-card border bg-raised p-4 shadow-card transition-colors sm:gap-5 sm:p-5',
                active ? 'border-2 border-gold' : 'border-rule',
            )}
        >
            <div className={cx('flex size-[84px] shrink-0 items-center justify-center overflow-hidden rounded-[14px] sm:size-[108px]', photo === undefined && tint)}>
                {photo !== undefined ? (
                    <img src={photo.url} alt="" className="h-full w-full object-cover" />
                ) : (
                    <span className="font-display text-display-m text-ink/35">{initials}</span>
                )}
            </div>
            <div className="flex min-w-0 flex-1 flex-col">
                <div className="flex items-start justify-between gap-3">
                    <p className="text-body font-extrabold text-ink">{entry.tradingName}</p>
                    {entry.distanceKm !== null && <span className="shrink-0 text-ui font-bold text-muted">{entry.distanceKm} km</span>}
                </div>
                <p className="mt-0.5 truncate text-ui text-muted">
                    {[entry.sector ?? entry.structureType, entry.ward].filter(Boolean).join(' · ')}
                </p>
                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <DepthMark depth={entry.depth} />
                    {entry.depth === 'verified' && entry.establishedOn !== null && (
                        <span className="text-[0.75rem] text-muted">
                            since {new Date(entry.establishedOn).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}
                        </span>
                    )}
                    {entry.rating !== null && (
                        <span className="text-table font-bold text-ink">
                            ★ {entry.rating.toFixed(1)} <span className="font-normal text-muted">({entry.reviewCount})</span>
                        </span>
                    )}
                    {entry.openNow === true && <span className="text-table font-bold text-green">Open now</span>}
                    {cell !== null && <span className="numeric-mono text-[0.75rem] text-muted">cell {cell}</span>}
                </div>
                <div className="mt-auto flex flex-wrap gap-2 pt-4">
                    <Link
                        href={`/directory/${String(entry.id)}`}
                        className="inline-flex min-h-touch items-center rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                    >
                        View business
                    </Link>
                    {published && (
                        <a
                            href={directionsUrl({ name: entry.tradingName, address: entry.address, ward: entry.ward, lga: entry.lga })}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex min-h-touch items-center rounded-sm bg-sunken px-4 text-ui font-bold text-ink hover:bg-rule"
                        >
                            Directions
                        </a>
                    )}
                    {published && entry.payable && (
                        <Link
                            href={`/directory/${String(entry.id)}?visit=1`}
                            className="inline-flex min-h-touch items-center rounded-sm bg-sunken px-4 text-ui font-bold text-ink hover:bg-rule"
                        >
                            Book a visit
                        </Link>
                    )}
                    {onShow !== null && (
                        <button
                            type="button"
                            onClick={onShow}
                            className="hidden min-h-touch items-center rounded-sm bg-sunken px-4 text-ui font-bold text-ink hover:bg-rule lg:inline-flex"
                        >
                            Show on map
                        </button>
                    )}
                    {signedIn ? (
                        <button
                            type="button"
                            aria-pressed={saved}
                            aria-label={saved ? `Remove ${entry.tradingName} from saved` : `Save ${entry.tradingName}`}
                            onClick={() => {
                                router.post(`/portal/saved/${String(entry.id)}`, {}, { preserveScroll: true });
                            }}
                            className={cx('inline-flex min-h-touch items-center rounded-sm px-3 text-ui font-bold', saved ? 'bg-gold-soft text-gold-dark' : 'bg-sunken text-ink hover:bg-rule')}
                        >
                            {saved ? 'Saved' : 'Save'}
                        </button>
                    ) : (
                        <Link href="/portal/sign-in" className="inline-flex min-h-touch items-center rounded-sm bg-sunken px-3 text-ui font-bold text-ink hover:bg-rule">
                            Save
                        </Link>
                    )}
                </div>
            </div>
        </li>
    );
}
