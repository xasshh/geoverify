import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { InvestorShell } from '@/components/InvestorShell';

interface Props {
    profile: { name: string; title: string | null; email: string };
    organisation: { name: string | null; kind: string | null; kycStatus: string | null; kycDecidedAt: string | null };
    team: { id: number; name: string; title: string | null; email: string; status: string }[];
}

const KYC: Record<string, string> = {
    pending: 'Awaiting verification',
    verified: 'Verified',
    suspended: 'Suspended',
};

/** Your profile, your organisation's standing, and who else signs in for it. */
export default function Settings({ profile, organisation, team }: Props) {
    const form = useForm({ name: profile.name, title: profile.title ?? '' });

    return (
        <InvestorShell current="settings" title="Settings" subtitle={organisation.name ?? undefined}>
            <Head title="Settings" />
            <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
                <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                    <h2 className="font-display text-display-s text-ink">Your profile</h2>
                    <form
                        className="mt-5 flex flex-col gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/invest/settings/profile', { preserveScroll: true });
                        }}
                    >
                        <TextField label="Name" value={form.data.name} onChange={(e) => { form.setData('name', e.target.value); }} {...(form.errors.name === undefined ? {} : { error: form.errors.name })} />
                        <TextField label="Role" value={form.data.title} onChange={(e) => { form.setData('title', e.target.value); }} />
                        <TextField label="Email" value={profile.email} disabled hint="Your sign-in address. Contact us to change it." />
                        <div>
                            <Button type="submit" variant="primary" size="field" busy={form.processing}>
                                Save
                            </Button>
                        </div>
                    </form>
                </section>

                <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                    <h2 className="font-display text-display-s text-ink">Organisation</h2>
                    <dl className="mt-4 flex flex-col">
                        {(
                            [
                                ['Name', organisation.name ?? ''],
                                ['Kind', organisation.kind ?? ''],
                                ['Verification', KYC[organisation.kycStatus ?? 'pending'] ?? ''],
                                ['Decided', organisation.kycDecidedAt ?? 'Not yet'],
                            ] as const
                        ).map(([k, v]) => (
                            <div key={k} className="flex justify-between gap-4 border-b border-rule py-3 last:border-b-0">
                                <dt className="text-ui text-muted">{k}</dt>
                                <dd className="text-ui font-bold text-ink">{v}</dd>
                            </div>
                        ))}
                    </dl>

                    <h3 className="mt-6 text-label font-bold tracking-[0.05em] text-muted uppercase">Team</h3>
                    <ul className="mt-2 flex list-none flex-col">
                        {team.map((m) => (
                            <li key={m.id} className="flex justify-between gap-4 border-b border-rule py-3 last:border-b-0">
                                <span className="flex flex-col">
                                    <span className="text-ui font-bold text-ink">{m.name}</span>
                                    <span className="text-table text-muted">{m.email}</span>
                                </span>
                                <span className="text-table text-muted">{m.title ?? ''}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </InvestorShell>
    );
}
