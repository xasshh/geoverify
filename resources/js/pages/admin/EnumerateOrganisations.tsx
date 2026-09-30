import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { cx } from '@/lib/cx';

interface Organisation {
    id: number;
    name: string;
    rcNumber: string | null;
    email: string | null;
    status: 'pending' | 'approved' | 'suspended';
    note: string | null;
    seats: number;
    manager: string | null;
    managerId: number | null;
    openedAt: string | null;
}

interface Project {
    id: number;
    reference: string;
    name: string;
    organisation: string | null;
    subject: string;
    area: string;
    target: number | null;
    wantedBy: string | null;
    fields: { label: string; type: string }[];
    notes: string | null;
    status: string;
    campaignId: number | null;
    campaign: string | null;
}

interface Props {
    organisations: Organisation[];
    projects: Project[];
    managers: { id: number; name: string }[];
    campaigns: { id: number; label: string }[];
    statuses: Record<string, string>;
}

const STATUS: Record<Organisation['status'], string> = {
    pending: 'bg-amber-soft text-amber-ink',
    approved: 'bg-green-soft text-green',
    suspended: 'bg-alert-soft text-alert-ink',
};

function OrganisationRow({ o, managers }: { o: Organisation; managers: Props['managers'] }) {
    const [note, setNote] = useState('');

    return (
        <li className="grid gap-3 border-b border-rule px-5 py-4 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] lg:items-center">
            <div className="min-w-0">
                <p className="flex items-center gap-2">
                    <span className="truncate text-body font-bold text-ink">{o.name}</span>
                    <span className={cx('rounded-full px-2 py-0.5 text-[0.75rem] font-bold', STATUS[o.status])}>{o.status}</span>
                </p>
                <p className="text-table text-muted">
                    {[o.rcNumber, o.email, `${String(o.seats)} ${o.seats === 1 ? 'seat' : 'seats'}`].filter(Boolean).join(' · ')}
                    {o.note !== null && ` · ${o.note}`}
                </p>
            </div>
            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap gap-2">
                    <select
                        value={o.managerId ?? ''}
                        aria-label={`Account manager for ${o.name}`}
                        onChange={(e) => { router.post(`/admin/enumerate-organisations/${String(o.id)}/manager`, { manager_id: Number(e.target.value) }, { preserveScroll: true }); }}
                        className="h-9 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-2 text-table"
                    >
                        <option value="" disabled>
                            Account manager
                        </option>
                        {managers.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </select>
                    {o.status !== 'approved' && (
                        <Button variant="primary" onClick={() => { router.post(`/admin/enumerate-organisations/${String(o.id)}/decide`, { approve: true, note: note === '' ? null : note }, { preserveScroll: true }); }}>
                            Approve
                        </Button>
                    )}
                    {o.status !== 'suspended' && (
                        <Button variant="destructive" disabled={note.trim().length < 10} onClick={() => { router.post(`/admin/enumerate-organisations/${String(o.id)}/decide`, { approve: false, note }, { preserveScroll: true }); }}>
                            Suspend
                        </Button>
                    )}
                </div>
                <input
                    value={note}
                    onChange={(e) => { setNote(e.target.value); }}
                    placeholder="Note (needed to suspend; the organisation reads it)"
                    aria-label={`Note for ${o.name}`}
                    className="h-9 rounded-sm border border-rule-strong bg-raised px-2 text-table"
                />
            </div>
        </li>
    );
}

function ProjectRow({ p, campaigns, statuses }: { p: Project; campaigns: Props['campaigns']; statuses: Props['statuses'] }) {
    const [status, setStatus] = useState(p.status);
    const [campaign, setCampaign] = useState<number | ''>(p.campaignId ?? '');

    return (
        <li className="grid gap-3 border-b border-rule px-5 py-4 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div className="min-w-0">
                <p className="font-mono text-[0.75rem] text-muted">{p.reference} · {p.organisation}</p>
                <p className="text-body font-bold text-ink">{p.name}</p>
                <p className="text-table text-muted">
                    {p.subject} · {p.area}
                    {p.target !== null && ` · ${p.target.toLocaleString('en-NG')} records`}
                    {p.wantedBy !== null && ` · by ${p.wantedBy}`}
                </p>
                <p className="mt-1 text-table text-ink">{p.fields.map((f) => `${f.label} (${f.type})`).join(', ')}</p>
                {p.notes !== null && <p className="mt-1 text-table text-muted">{p.notes}</p>}
            </div>
            <div className="flex flex-wrap items-start gap-2">
                <select value={status} onChange={(e) => { setStatus(e.target.value); }} aria-label={`Status of ${p.name}`} className="h-9 rounded-sm border border-rule-strong bg-raised px-2 text-table">
                    {Object.entries(statuses).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
                <select
                    value={campaign}
                    onChange={(e) => { setCampaign(e.target.value === '' ? '' : Number(e.target.value)); }}
                    aria-label={`Campaign for ${p.name}`}
                    className="h-9 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-2 text-table"
                >
                    <option value="">No campaign yet</option>
                    {campaigns.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.label}
                        </option>
                    ))}
                </select>
                <Button
                    variant="primary"
                    onClick={() => { router.post(`/admin/enumerate-projects/${String(p.id)}`, { status, campaign_id: campaign === '' ? null : campaign }, { preserveScroll: true }); }}
                >
                    Save
                </Button>
            </div>
        </li>
    );
}

/**
 * Enumerate organisations from the inside: approve or suspend them, give each
 * an account manager, and move their projects on to the campaigns that run
 * them. A project goes live only with a campaign linked.
 */
export default function EnumerateOrganisations({ organisations, projects, managers, campaigns, statuses }: Props) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;

    return (
        <ConsoleShell current="enumerateOrganisations">
            <Head title="Organisations" />
            <div className="mx-auto max-w-[1320px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <h1 className="font-display text-display-l text-ink">Organisations</h1>
                    <p className="mt-1 text-ui text-muted">Enumerate for Organisations. Pending first. Approval opens bulk verification and projects.</p>
                </header>

                {page.props.flash.status !== null && <p className="mt-5 max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{page.props.flash.status}</p>}
                {errors.organisation !== undefined && <p className="mt-5 max-w-none rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">{errors.organisation}</p>}

                <h2 className="mt-6 mb-3 text-body font-extrabold text-ink">Organisations</h2>
                {organisations.length === 0 ? (
                    <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted">None yet.</p>
                ) : (
                    <ul className="overflow-hidden rounded-card border border-rule bg-raised">
                        {organisations.map((o) => <OrganisationRow key={o.id} o={o} managers={managers} />)}
                    </ul>
                )}

                <h2 className="mt-8 mb-3 text-body font-extrabold text-ink">Projects</h2>
                {projects.length === 0 ? (
                    <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted">None yet.</p>
                ) : (
                    <ul className="overflow-hidden rounded-card border border-rule bg-raised">
                        {projects.map((p) => <ProjectRow key={p.id} p={p} campaigns={campaigns} statuses={statuses} />)}
                    </ul>
                )}
            </div>
        </ConsoleShell>
    );
}
