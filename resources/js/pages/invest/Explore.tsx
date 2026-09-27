import { Head, router, usePage } from '@inertiajs/react';
import { ExploreMap, type ExploreMapData } from '@/components/ExploreMap';
import { InvestorShell } from '@/components/InvestorShell';
import {
    OpportunityTable,
    SectorBars,
    StateTileMap,
    type OpportunityRow,
    type StateCount,
} from '@/components/InvestorWidgets';

interface Props {
    map: ExploreMapData;
    selected: string | null;
    overview: {
        states: StateCount[];
        sectors: { code: string; name: string; share: number }[];
    };
    opportunities: OpportunityRow[];
}

/**
 * The country at a glance: every state, how many verified businesses it holds
 * and how many are seeking investment. Choosing a state lists its
 * opportunities under the map.
 */
export default function Explore({ map, selected, overview, opportunities }: Props) {
    const verified = usePage().props.auth.investor?.verified === true;
    const total = overview.states.reduce((n, s) => n + s.verified, 0);

    return (
        <InvestorShell
            current="explore"
            title="Explore map"
            subtitle={`${total.toLocaleString('en-NG')} verified businesses across ${String(overview.states.length)} ${overview.states.length === 1 ? 'state' : 'states'} on the register`}
        >
            <Head title="Explore map" />
            <div className="mb-6">
                <ExploreMap data={map} />
            </div>
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <section className="overflow-x-auto rounded-card border border-rule bg-raised p-7 shadow-card">
                    <StateTileMap
                        size={64}
                        counts={overview.states}
                        selected={selected}
                        onSelect={(state) => {
                            router.get('/invest/explore', state === selected ? {} : { region: state }, {
                                preserveScroll: true,
                                preserveState: true,
                            });
                        }}
                    />
                </section>
                <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                    <h2 className="font-display text-display-s text-ink">Sectors</h2>
                    <p className="mt-1 text-table text-muted">Share of verified businesses nationally</p>
                    <SectorBars sectors={overview.sectors} />
                </section>
            </div>

            <section className="mt-9">
                <h2 className="mb-4 font-display text-display-s text-ink">
                    {selected === null ? 'All opportunities' : `Opportunities in ${selected}`}
                </h2>
                {verified ? (
                    <OpportunityTable rows={opportunities} empty="No business here has published an opportunity yet." />
                ) : (
                    <p className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                        Opportunities name businesses, so they open once your organisation is verified.
                    </p>
                )}
            </section>
        </InvestorShell>
    );
}
