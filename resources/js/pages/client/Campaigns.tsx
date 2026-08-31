import { Head, Link } from '@inertiajs/react';
import { ClientShell } from '@/components/ClientShell';
import { StatusPill } from '@/components/StatusPill';
import { campaignTone, on } from '@/lib/campaign';

interface Row {
    id: number;
    code: string;
    name: string;
    subjectType: string;
    status: string;
    statusLabel: string;
    startsOn: string | null;
    endsOn: string | null;
    targetRecordCount: number | null;
    areaCount: number;
}

/**
 * Everything this client has ever commissioned.
 *
 * Finished and archived exercises sit here alongside the live ones, in full.
 * "What did you do for us last year" is the question that renews a contract,
 * and a list that only shows what is running cannot answer it.
 */
export default function Campaigns({ campaigns }: { campaigns: Row[] }) {
    return (
        <ClientShell current="campaigns">
            <Head title="Campaigns" />

            <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                <h1 className="font-display text-display-m text-ink">All campaigns</h1>
                <p className="numeric-mono text-mono text-muted">{campaigns.length} in total</p>
            </header>

            <ul className="mt-6 flex flex-col gap-3">
                {campaigns.map((campaign) => (
                    <li key={campaign.id} className="rounded-sm border border-rule-strong">
                        <Link
                            href={`/client/campaigns/${String(campaign.id)}`}
                            className="flex flex-wrap items-start justify-between gap-4 px-5 py-4 hover:bg-raised"
                        >
                            <div className="min-w-[240px] flex-1">
                                <p className="numeric-mono text-label text-faint">{campaign.code}</p>
                                <p className="font-display text-display-s text-ink">
                                    {campaign.name}
                                </p>
                                <p className="text-ui text-muted">{campaign.subjectType}</p>
                            </div>

                            <div className="flex flex-wrap items-center gap-x-8 gap-y-2">
                                <span className="numeric-mono text-label text-muted">
                                    {on(campaign.startsOn)} to {on(campaign.endsOn)}
                                </span>
                                <span className="numeric-mono text-label text-muted">
                                    {campaign.areaCount}{' '}
                                    {campaign.areaCount === 1 ? 'area' : 'areas'}
                                </span>
                                <span className="numeric-mono text-label text-muted">
                                    {campaign.targetRecordCount === null
                                        ? 'no target'
                                        : `${campaign.targetRecordCount.toLocaleString()} target`}
                                </span>
                                <StatusPill
                                    tone={campaignTone(campaign.status)}
                                    label={campaign.statusLabel}
                                />
                            </div>
                        </Link>
                    </li>
                ))}

                {campaigns.length === 0 && (
                    <li className="rounded-sm border border-dashed border-rule-strong px-6 py-10 text-center text-ui text-muted">
                        Nothing has been commissioned yet.
                    </li>
                )}
            </ul>
        </ClientShell>
    );
}
