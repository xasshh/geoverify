import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { ActiveCampaignCard, CampaignIntroModal } from '@/components/CampaignWidgets';
import { ClientShell } from '@/components/ClientShell';
import { SelectField } from '@/components/Field';
import type { CampaignDossier } from '@/lib/campaign';

interface Props {
    organisation: { name: string | null; shortCode: string | null };
    active: Array<{ id: number; code: string; name: string }>;
    campaign: CampaignDossier | null;
    mustAcknowledge: boolean;
}

/**
 * What is running right now.
 *
 * One active campaign shows directly. Several show a switcher, and the server
 * remembers the last one chosen in the session, because a client with two
 * exercises should not have to re-pick on every visit.
 *
 * None shows an explanation rather than an empty card. A client whose exercise
 * finished last month should read "nothing is running" and not wonder whether
 * the page is broken.
 */
export default function Dashboard({ organisation, active, campaign, mustAcknowledge }: Props) {
    const [dismissed, setDismissed] = useState(false);

    return (
        <ClientShell current="dashboard" organisation={organisation}>
            <Head title="Active campaign" />

            <header className="mt-8 flex flex-wrap items-end justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                <div>
                    <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                        {organisation.name ?? 'Your organisation'}
                    </p>
                    <h1 className="font-display text-display-m text-ink">Active campaign</h1>
                </div>

                {active.length > 1 && campaign !== null && (
                    <SelectField
                        label="Showing"
                        value={String(campaign.id)}
                        onChange={(event) => {
                            router.get(`/client/campaigns/${event.target.value}`);
                        }}
                    >
                        {active.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name}
                            </option>
                        ))}
                    </SelectField>
                )}
            </header>

            {campaign === null ? (
                <div className="mt-8 rounded-sm border border-dashed border-rule-strong px-6 py-10 text-center">
                    <p className="font-display text-display-s text-ink">
                        No exercise is running at the moment.
                    </p>
                    <p className="mx-auto mt-2 max-w-[52ch] text-body text-muted">
                        Nothing is currently being enumerated for {organisation.name ?? 'you'}.
                        Campaigns that have finished are kept in full, with their coverage,
                        schema and findings, under All campaigns.
                    </p>
                </div>
            ) : (
                <div className="mt-6">
                    <ActiveCampaignCard campaign={campaign} />
                </div>
            )}

            {campaign !== null && mustAcknowledge && !dismissed && (
                <CampaignIntroModal
                    campaign={campaign}
                    onDismissed={() => {
                        setDismissed(true);
                    }}
                />
            )}
        </ClientShell>
    );
}
