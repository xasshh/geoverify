import { Head, router } from '@inertiajs/react';
import { InvestorShell } from '@/components/InvestorShell';
import { FilterSelect, OpportunityTable, type OpportunityRow } from '@/components/InvestorWidgets';

interface Props {
    filters: { region: string | null; sector: string | null; seeking: string | null };
    regions: string[];
    sectorOptions: { code: string; name: string }[];
    seekingOptions: { value: string; label: string }[];
    opportunities: OpportunityRow[];
}

/** Every published opportunity, highest verification score first. */
export default function Opportunities({ filters, regions, sectorOptions, seekingOptions, opportunities }: Props) {
    const apply = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, ...next };
        router.get('/invest/opportunities', Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== null)), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <InvestorShell
            current="opportunities"
            title="Opportunities"
            subtitle={`${String(opportunities.length)} verified ${opportunities.length === 1 ? 'business is' : 'businesses are'} seeking investment`}
        >
            <Head title="Opportunities" />
            <div className="mb-5 flex flex-wrap gap-4">
                <FilterSelect label="Region" value={filters.region} all="All Nigeria" options={regions.map((r) => ({ value: r, label: r }))} onChange={(v) => { apply({ region: v }); }} />
                <FilterSelect label="Sector" value={filters.sector} all="All sectors" options={sectorOptions.map((s) => ({ value: s.code, label: s.name }))} onChange={(v) => { apply({ sector: v }); }} />
                <FilterSelect label="Seeking" value={filters.seeking} all="Anything" options={seekingOptions} onChange={(v) => { apply({ seeking: v }); }} />
            </div>
            <OpportunityTable rows={opportunities} empty="No opportunity matches these filters." />
        </InvestorShell>
    );
}
