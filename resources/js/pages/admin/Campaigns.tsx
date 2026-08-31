import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { SelectField, TextField } from '@/components/Field';
import { StatusPill } from '@/components/StatusPill';
import { campaignTone, on } from '@/lib/campaign';

interface Row {
    id: number;
    code: string;
    name: string;
    subjectType: string;
    client: string | null;
    status: string;
    statusLabel: string;
    startsOn: string | null;
    endsOn: string | null;
    areaCount: number;
    targetRecordCount: number | null;
}

interface Props {
    campaigns: Row[];
    filters: Record<string, string | undefined>;
    clients: Array<{ id: number; name: string }>;
    statuses: Array<{ value: string; label: string }>;
    states: string[];
}

/**
 * Every campaign, across every client.
 *
 * The one view a client can never have. Filters exist because the question is
 * usually narrow: what is this client running, what is awaiting approval, what
 * is live in Kaduna this quarter.
 */
export default function Campaigns({ campaigns, filters, clients, statuses, states }: Props) {
    const [creating, setCreating] = useState(false);
    const [draft, setDraft] = useState({
        client: filters.client ?? '',
        status: filters.status ?? '',
        state: filters.state ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const form = useForm({
        client_organisation_id: clients[0]?.id ?? 0,
        name: '',
        subject_type: '',
        objective: '',
        starts_on: '',
        ends_on: '',
        target_record_count: '',
    });

    const apply = () => {
        router.get(
            '/admin/campaigns',
            Object.fromEntries(Object.entries(draft).filter(([, v]) => v !== '')),
            { preserveState: true },
        );
    };

    return (
        <ConsoleShell current="campaigns">
            <Head title="Campaigns" />

            <div className="mx-auto max-w-[1300px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b-[1.5px] border-ink pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            In house
                        </p>
                        <h1 className="font-display text-display-m text-ink">Campaigns</h1>
                    </div>
                    <Button
                        onClick={() => {
                            setCreating((v) => !v);
                        }}
                    >
                        {creating ? 'Cancel' : 'Commission a campaign'}
                    </Button>
                </header>

                {creating && (
                    <section className="mt-6 rounded-sm border border-rule-strong p-4">
                        <h2 className="font-display text-display-s text-ink">
                            Commission a campaign
                        </h2>
                        <p className="mt-1 mb-3 max-w-[68ch] text-ui text-muted">
                            Starts as a draft, invisible to the client until it is approved. Scope,
                            schema, stakeholders, deployment and commercials are filled in
                            afterwards, on the campaign itself.
                        </p>

                        <div className="flex flex-wrap items-end gap-3">
                            <SelectField
                                label="Client"
                                value={String(form.data.client_organisation_id)}
                                onChange={(e) => {
                                    form.setData('client_organisation_id', Number(e.target.value));
                                }}
                            >
                                {clients.map((client) => (
                                    <option key={client.id} value={client.id}>
                                        {client.name}
                                    </option>
                                ))}
                            </SelectField>
                            <TextField
                                label="Name"
                                value={form.data.name}
                                onChange={(e) => {
                                    form.setData('name', e.target.value);
                                }}
                            />
                            <TextField
                                label="Subject"
                                placeholder="Mining companies"
                                value={form.data.subject_type}
                                onChange={(e) => {
                                    form.setData('subject_type', e.target.value);
                                }}
                            />
                            <TextField
                                label="Starts"
                                type="date"
                                value={form.data.starts_on}
                                onChange={(e) => {
                                    form.setData('starts_on', e.target.value);
                                }}
                            />
                            <TextField
                                label="Ends"
                                type="date"
                                value={form.data.ends_on}
                                onChange={(e) => {
                                    form.setData('ends_on', e.target.value);
                                }}
                            />
                            <TextField
                                label="Target records"
                                type="number"
                                value={form.data.target_record_count}
                                onChange={(e) => {
                                    form.setData('target_record_count', e.target.value);
                                }}
                            />
                            <Button
                                onClick={() => {
                                    form.post('/admin/campaigns');
                                }}
                                disabled={form.processing || form.data.name === ''}
                            >
                                Create
                            </Button>
                        </div>

                        {Object.values(form.errors).map((error) => (
                            <p key={error} className="mt-2 text-label text-alert">
                                {error}
                            </p>
                        ))}
                    </section>
                )}

                <div className="mt-6 flex flex-wrap items-end gap-3 rounded-sm border border-rule-strong p-4">
                    <SelectField
                        label="Client"
                        value={draft.client}
                        onChange={(e) => {
                            setDraft({ ...draft, client: e.target.value });
                        }}
                    >
                        <option value="">Everyone</option>
                        {clients.map((client) => (
                            <option key={client.id} value={client.id}>
                                {client.name}
                            </option>
                        ))}
                    </SelectField>

                    <SelectField
                        label="Status"
                        value={draft.status}
                        onChange={(e) => {
                            setDraft({ ...draft, status: e.target.value });
                        }}
                    >
                        <option value="">Any</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </SelectField>

                    <SelectField
                        label="State"
                        value={draft.state}
                        onChange={(e) => {
                            setDraft({ ...draft, state: e.target.value });
                        }}
                    >
                        <option value="">Anywhere</option>
                        {states.map((state) => (
                            <option key={state} value={state}>
                                {state}
                            </option>
                        ))}
                    </SelectField>

                    <TextField
                        label="Starting from"
                        type="date"
                        value={draft.from}
                        onChange={(e) => {
                            setDraft({ ...draft, from: e.target.value });
                        }}
                    />
                    <TextField
                        label="Ending by"
                        type="date"
                        value={draft.to}
                        onChange={(e) => {
                            setDraft({ ...draft, to: e.target.value });
                        }}
                    />

                    <Button onClick={apply}>Apply</Button>
                </div>

                <ul className="mt-6 flex flex-col rounded-sm border border-rule-strong px-4">
                    {campaigns.map((campaign) => (
                        <li key={campaign.id} className="border-b border-rule last:border-b-0">
                            <Link
                                href={`/admin/campaigns/${String(campaign.id)}`}
                                className="flex flex-wrap items-baseline gap-x-6 gap-y-1 py-3"
                            >
                                <span className="numeric-mono w-[160px] shrink-0 text-label text-faint">
                                    {campaign.code}
                                </span>
                                <span className="min-w-[220px] flex-1 text-ui text-ink">
                                    {campaign.name}
                                    <span className="block text-label text-faint">
                                        {campaign.subjectType}
                                    </span>
                                </span>
                                <span className="w-[180px] text-label text-muted">
                                    {campaign.client}
                                </span>
                                <span className="numeric-mono w-[190px] text-label text-faint">
                                    {on(campaign.startsOn)} to {on(campaign.endsOn)}
                                </span>
                                <span className="numeric-mono w-[70px] text-label text-faint">
                                    {campaign.areaCount} areas
                                </span>
                                <StatusPill
                                    tone={campaignTone(campaign.status)}
                                    label={campaign.statusLabel}
                                    size="sm"
                                />
                            </Link>
                        </li>
                    ))}

                    {campaigns.length === 0 && (
                        <li className="py-4 text-ui text-muted">Nothing matches those filters.</li>
                    )}
                </ul>
            </div>
        </ConsoleShell>
    );
}
