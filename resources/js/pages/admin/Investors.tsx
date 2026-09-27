import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { StatusPill } from '@/components/StatusPill';
import type { StatusTone } from '@/lib/status';

interface Organisation {
    id: number;
    name: string;
    kind: string;
    website: string | null;
    kycStatus: 'pending' | 'verified' | 'suspended';
    decidedAt: string | null;
    note: string | null;
    requestedAt: string | null;
    people: { name: string; title: string | null; email: string }[];
}

const TONE: Record<Organisation['kycStatus'], { tone: StatusTone; label: string }> = {
    pending: { tone: 'review', label: 'Awaiting KYC' },
    verified: { tone: 'accepted', label: 'Verified' },
    suspended: { tone: 'rejected', label: 'Suspended' },
};

/**
 * Investor organisations, and the KYC ruling on each.
 *
 * A verified organisation can read dossiers and ask for data rooms. What was
 * checked is written in the note: the system records the ruling, not the
 * judgement behind it.
 */
export default function Investors({ organisations }: { organisations: Organisation[] }) {
    const flash = usePage().props.flash.status;

    return (
        <ConsoleShell current="investors">
            <Head title="Investors" />
            <div className="mx-auto max-w-[1100px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <p className="text-label font-bold tracking-[0.05em] text-muted uppercase">Administration</p>
                    <h1 className="font-display text-display-m text-ink">Investors</h1>
                    <p className="mt-1 max-w-[68ch] text-ui text-muted">
                        Organisations that asked to use the investor portal. Verify one only once
                        you have checked it is who it says it is.
                    </p>
                </header>

                {flash !== null && (
                    <p role="status" className="mt-6 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                        {flash}
                    </p>
                )}

                {organisations.length === 0 ? (
                    <p className="mt-8 rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                        No organisation has asked for access yet.
                    </p>
                ) : (
                    <ul className="mt-6 flex list-none flex-col gap-4">
                        {organisations.map((o) => (
                            <OrganisationCard key={o.id} organisation={o} />
                        ))}
                    </ul>
                )}
            </div>
        </ConsoleShell>
    );
}

function OrganisationCard({ organisation }: { organisation: Organisation }) {
    const form = useForm({ note: '' });
    const look = TONE[organisation.kycStatus];

    // The decision travels with the press that made it, never through state
    // set a moment earlier, so Suspend can never post as Verify.
    const decide = (decision: 'verified' | 'suspended') => {
        form.transform((data) => ({ ...data, decision }));
        form.post(`/admin/investors/${String(organisation.id)}/decide`, { preserveScroll: true });
    };

    return (
        <li className="rounded-card border border-rule bg-raised p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="font-display text-display-s text-ink">{organisation.name}</h2>
                    <p className="text-ui text-muted">
                        {organisation.kind}
                        {organisation.website !== null && (
                            <>
                                {' · '}
                                <a href={organisation.website} className="underline underline-offset-4" rel="noreferrer noopener" target="_blank">
                                    {organisation.website}
                                </a>
                            </>
                        )}
                    </p>
                </div>
                <StatusPill tone={look.tone} label={look.label} />
            </div>

            <ul className="mt-4 flex list-none flex-col gap-1">
                {organisation.people.map((p) => (
                    <li key={p.email} className="text-ui text-ink">
                        <span className="font-bold">{p.name}</span>
                        {p.title !== null && ` · ${p.title}`} · <span className="numeric-mono text-mono">{p.email}</span>
                    </li>
                ))}
            </ul>

            {organisation.note !== null && (
                <p className="mt-3 rounded-sm bg-sunken px-4 py-3 text-ui text-muted">{organisation.note}</p>
            )}

            <form
                className="mt-5 flex flex-wrap items-end gap-3 border-t border-rule pt-5"
                onSubmit={(e) => {
                    e.preventDefault();
                }}
            >
                <label className="flex min-w-[260px] flex-1 flex-col gap-1.5">
                    <span className="text-label font-bold tracking-[0.05em] text-muted uppercase">What you checked</span>
                    <input
                        value={form.data.note}
                        onChange={(e) => {
                            form.setData('note', e.target.value);
                        }}
                        className="h-11 rounded-sm border border-rule-strong bg-raised px-3.5 text-ui text-ink focus:border-gold"
                    />
                    {form.errors.note !== undefined && <span className="text-table text-alert-ink">{form.errors.note}</span>}
                </label>
                <Button
                    variant="primary"
                    disabled={form.processing}
                    onClick={() => {
                        decide('verified');
                    }}
                >
                    Verify
                </Button>
                <Button
                    variant="destructive"
                    disabled={form.processing}
                    onClick={() => {
                        decide('suspended');
                    }}
                >
                    Suspend
                </Button>
            </form>
        </li>
    );
}
