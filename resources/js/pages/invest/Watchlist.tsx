import { Head } from '@inertiajs/react';
import { InvestorShell } from '@/components/InvestorShell';
import { OpportunityTable, type OpportunityRow } from '@/components/InvestorWidgets';

/** The opportunities this organisation is tracking. */
export default function Watchlist({ opportunities }: { opportunities: OpportunityRow[] }) {
    return (
        <InvestorShell current="watchlist" title="Watchlist" subtitle="Verified businesses your organisation is tracking">
            <Head title="Watchlist" />
            <OpportunityTable
                rows={opportunities}
                empty="Nothing on your watchlist yet. Open a business and choose Watchlist to track it here."
            />
        </InvestorShell>
    );
}
