import { Head, Link, router, usePage } from '@inertiajs/react';
import { DeskFrame } from '@/components/DeskFrame';

interface Mandate {
    id: number;
    name: string;
    features: number;
    unverified: number;
    fromDesk: number;
}

interface CampaignRow {
    id: number;
    code: string;
    name: string;
    samplePct: number;
    openTasks: number;
    mandates: Mandate[];
}

/**
 * The desk's front page: every campaign that maps land, and its ground.
 *
 * From here a digitiser opens a mandate to draw on, and sends a sample of
 * what was drawn to officers to check.
 */
export default function DeskIndex({ campaigns }: { campaigns: CampaignRow[] }) {
    const flash = usePage().props.flash.status;

    return (
        <DeskFrame title="Area features">
            <Head title="Desk" />
            <div className="mx-auto max-w-[1100px] px-5 py-8">
                {flash !== null && (
                    <p role="status" className="mb-5 rounded-sm bg-green-soft px-4 py-2.5 text-ui text-ink">
                        {flash}
                    </p>
                )}
                <h1 className="font-display text-display-m">Ground to map</h1>
                <p className="mt-1 max-w-[70ch] text-ui text-muted">
                    Draw land, water and the things on it over satellite imagery, import what a client sends, or pre-draw
                    land cover from ESA WorldCover. Everything drawn here waits for an officer to check it on the ground.
                </p>

                {campaigns.length === 0 && (
                    <p className="mt-8 text-ui text-muted">
                        No campaign maps land yet. An administrator switches it on in the campaign&apos;s Capture tab.
                    </p>
                )}

                <ul className="mt-8 flex flex-col gap-5">
                    {campaigns.map((campaign) => (
                        <li key={campaign.id} className="rounded-card border border-rule bg-raised p-5">
                            <div className="flex flex-wrap items-baseline justify-between gap-3">
                                <div>
                                    <p className="numeric-mono text-label text-faint">{campaign.code}</p>
                                    <p className="font-display text-body font-extrabold">{campaign.name}</p>
                                </div>
                                <div className="flex items-center gap-4">
                                    <span className="text-label text-muted">
                                        {campaign.openTasks} open checks · sample {campaign.samplePct}%
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            router.post(`/desk/campaigns/${String(campaign.id)}/verification-tasks`, {}, { preserveScroll: true });
                                        }}
                                        className="rounded-full bg-gold-dark px-4 py-2 text-label font-extrabold text-on-accent hover:bg-gold"
                                    >
                                        Send a sample for checking
                                    </button>
                                </div>
                            </div>
                            <ul className="mt-4 flex flex-col border-t border-rule">
                                {campaign.mandates.map((mandate) => (
                                    <li key={mandate.id} className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2.5 last:border-b-0">
                                        <Link href={`/desk/mandates/${String(mandate.id)}`} className="min-w-[200px] flex-1 text-ui font-semibold text-gold-dark underline underline-offset-2">
                                            {mandate.name}
                                        </Link>
                                        <span className="numeric-mono text-label text-muted">{mandate.features.toLocaleString()} features</span>
                                        <span className="numeric-mono text-label text-muted">{mandate.fromDesk.toLocaleString()} from the desk</span>
                                        <span className="numeric-mono text-label text-amber-ink">{mandate.unverified.toLocaleString()} unchecked</span>
                                    </li>
                                ))}
                                {campaign.mandates.length === 0 && <li className="py-2.5 text-ui text-muted">No ground in this campaign yet.</li>}
                            </ul>
                        </li>
                    ))}
                </ul>
            </div>
        </DeskFrame>
    );
}
