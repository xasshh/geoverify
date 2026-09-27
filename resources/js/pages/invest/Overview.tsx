import { Head, Link, router, usePage } from '@inertiajs/react';
import { InvestorIcon, InvestorShell } from '@/components/InvestorShell';
import {
    CountCard,
    FilterSelect,
    OpportunityTable,
    SectorBars,
    StateTileMap,
    type OpportunityRow,
    type StateCount,
} from '@/components/InvestorWidgets';

interface OverviewProps {
    filters: { region: string | null; sector: string | null };
    regions: string[];
    sectorOptions: { code: string; name: string }[];
    overview: {
        states: StateCount[];
        sectors: { code: string; name: string; count: number; share: number }[];
        unclassified: number;
        counts: { watchlist: number; dataRooms: number; verifications: number };
    };
    featured: OpportunityRow[];
}

/**
 * The investor overview: one action, three counts, then where verified
 * businesses are, what they do, and the opportunities worth opening first.
 */
export default function Overview({ filters, regions, sectorOptions, overview, featured }: OverviewProps) {
    const verified = usePage().props.auth.investor?.verified === true;

    const apply = (next: Partial<OverviewProps['filters']>) => {
        const merged = { ...filters, ...next };
        router.get(
            '/invest',
            Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== null)),
            { preserveScroll: true, preserveState: true },
        );
    };

    const selected = filters.region ?? overview.states[0]?.state ?? null;

    return (
        <InvestorShell
            current="overview"
            title="Overview"
            subtitle="Verified businesses and opportunities across Nigeria"
            actions={
                <>
                    <FilterSelect
                        label="Region"
                        value={filters.region}
                        all="All Nigeria"
                        options={regions.map((r) => ({ value: r, label: r }))}
                        onChange={(v) => {
                            apply({ region: v });
                        }}
                    />
                    <FilterSelect
                        label="Sector"
                        value={filters.sector}
                        all="All sectors"
                        options={sectorOptions.map((s) => ({ value: s.code, label: s.name }))}
                        onChange={(v) => {
                            apply({ sector: v });
                        }}
                    />
                </>
            }
        >
            <Head title="Investor overview" />

            <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <Link
                    href={verified ? '/invest/verifications' : '/invest/settings'}
                    className="relative flex min-h-[250px] flex-col overflow-hidden rounded-card bg-ink p-7 text-inverse shadow-card hover:bg-[#1a2824]"
                >
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 120 138"
                        className="pointer-events-none absolute -right-10 -bottom-10 w-44 text-inverse/10"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.5"
                    >
                        <path d="M60 2 118 35v68L60 136 2 103V35z" />
                        <path d="M60 30 94 49v40L60 108 26 89V49z" />
                    </svg>
                    <span className="text-logo">
                        <InvestorIcon size={34} path="M10.5 17.5a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20.5 20.5l-5-5M8 10.5l2 2 3.5-3.5" />
                    </span>
                    <span className="mt-5 font-display text-display-m">Commission due diligence</span>
                    <span className="mt-2 text-ui text-inverse/75">
                        Send field agents to verify a business, site or asset before you commit.
                    </span>
                    <span className="mt-auto inline-flex items-center gap-2 pt-5 text-ui font-extrabold text-logo">
                        <InvestorIcon size={20} path="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 8v8M8 12h8" />
                        Start a verification
                    </span>
                </Link>

                <CountCard
                    tone="teal"
                    value={overview.counts.watchlist}
                    title="Watchlist"
                    body="Verified businesses you are tracking"
                    href="/invest/watchlist"
                    link="View watchlist"
                />
                <CountCard
                    tone="blue"
                    value={overview.counts.dataRooms}
                    title="Data rooms open"
                    body="Businesses that gave you document access"
                    href="/invest/data-rooms"
                    link="Open data rooms"
                />
                <CountCard
                    tone="amber"
                    value={overview.counts.verifications}
                    title="Verifications running"
                    body="Field agents are on site for your requests"
                    href="/invest/verifications"
                    link="Track progress"
                />
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="font-display text-display-s text-ink">
                                Verified businesses by state
                            </h2>
                            <p className="mt-1 text-table text-muted">
                                Each tile is a state. Darker means more verified businesses.
                            </p>
                        </div>
                        <Link href="/invest/explore" className="text-ui font-extrabold text-gold hover:text-gold-dark">
                            Open map
                        </Link>
                    </div>
                    <div className="mt-6 overflow-x-auto">
                        <StateTileMap
                            counts={overview.states}
                            selected={selected}
                            onSelect={(state) => {
                                apply({ region: state === filters.region ? null : state });
                            }}
                        />
                    </div>
                </section>

                <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                    <h2 className="font-display text-display-s text-ink">Sectors</h2>
                    <p className="mt-1 text-table text-muted">Share of verified businesses</p>
                    <SectorBars sectors={overview.sectors} />
                    {overview.unclassified > 0 && (
                        <p className="mt-4 text-table text-faint">
                            {overview.unclassified} more carry no sector yet and are not in these shares.
                        </p>
                    )}
                </section>
            </div>

            <section className="mt-9">
                <div className="mb-4 flex items-baseline justify-between gap-4">
                    <h2 className="font-display text-display-s text-ink">Featured opportunities</h2>
                    <Link href="/invest/opportunities" className="text-ui font-extrabold text-gold hover:text-gold-dark">
                        View all
                    </Link>
                </div>
                {verified ? (
                    <OpportunityTable
                        rows={featured}
                        empty="No business has published an opportunity in this selection yet."
                    />
                ) : (
                    <p className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                        Opportunities name businesses, so they open once your organisation is
                        verified.
                    </p>
                )}
            </section>
        </InvestorShell>
    );
}
