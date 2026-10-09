import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { SelectField, TextField } from '@/components/Field';
import { PortalShell } from '@/components/PortalShell';
import { cx } from '@/lib/cx';

interface Member {
    id: number;
    name: string | null;
    phone: string | null;
    email?: string | null;
    role: 'owner' | 'manager' | 'viewer';
    roleLabel: string;
    pending: boolean;
    since: string | null;
}

interface Props {
    party: { name: string | null; code: string | null };
    me: number;
    isOwner: boolean;
    roles: { value: string; label: string; can: string }[];
    members: Member[];
}

function initials(name: string | null): string {
    return (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
}

/**
 * Team & roles. The owner invites by email; the invitee proves the address and
 * accepts. Everyone signs in with the business ID and their own password, or a
 * code to their own phone.
 */
export default function Team({ party, me, isOwner, roles, members }: Props) {
    const page = usePage();
    const accountName = page.props.auth.portal?.name ?? '';
    const roleError = page.props.errors.role;
    const form = useForm({ name: '', email: '', role: roles[0]?.value ?? 'manager' });

    return (
        <PortalShell
            accountName={accountName}
            width="page"
            title="Team & roles"
            subtitle={
                <>
                    {party.name} · Business ID <span className="numeric-mono font-medium text-ink">{party.code}</span>
                </>
            }
        >
            <Head title="Team & roles" />
            {roleError !== undefined && <p className="mb-5 rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{roleError}</p>}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px] xl:items-start">
                <section className="overflow-hidden rounded-card border border-rule bg-raised shadow-card">
                    <ul className="flex list-none flex-col">
                        {members.map((m) => (
                            <li key={m.id} className="flex flex-wrap items-center gap-4 border-b border-rule px-6 py-4 last:border-b-0">
                                <span className="flex size-11 items-center justify-center rounded-full bg-gold-soft text-ui font-extrabold text-gold-dark">
                                    {initials(m.name)}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-ui font-extrabold text-ink">
                                        {m.name}
                                        {m.id === me && <span className="ml-2 text-table font-semibold text-muted">you</span>}
                                    </span>
                                    <span className="text-table text-muted">
                                        {m.email ?? m.phone} · {m.pending ? `invited ${m.since ?? ''}` : `since ${m.since ?? ''}`}
                                    </span>
                                </span>
                                {m.pending && <span className="rounded-full bg-amber-soft px-3 py-1 text-table font-bold text-amber-ink">Invitation sent</span>}
                                {isOwner && m.role !== 'owner' ? (
                                    <>
                                        <select
                                            aria-label={`Role for ${m.name ?? 'member'}`}
                                            value={m.role}
                                            onChange={(e) => {
                                                router.post(`/portal/team/${String(m.id)}/role`, { role: e.target.value }, { preserveScroll: true });
                                            }}
                                            className="h-10 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-bold text-ink"
                                        >
                                            {roles.map((r) => (
                                                <option key={r.value} value={r.value}>
                                                    {r.label}
                                                </option>
                                            ))}
                                        </select>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                router.post(`/portal/team/${String(m.id)}/revoke`, {}, { preserveScroll: true });
                                            }}
                                            className="text-table font-bold text-muted hover:text-alert-ink"
                                        >
                                            Remove
                                        </button>
                                    </>
                                ) : (
                                    <span
                                        className={cx(
                                            'rounded-full px-3 py-1 text-table font-bold',
                                            m.role === 'owner' ? 'bg-ink text-inverse' : 'bg-sunken text-ink',
                                        )}
                                    >
                                        {m.roleLabel}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <div className="flex flex-col gap-6">
                    {isOwner ? (
                        <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                            <h2 className="font-display text-display-s text-ink">Invite someone</h2>
                            <form
                                className="mt-4 flex flex-col gap-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post('/portal/team', { preserveScroll: true, onSuccess: () => { form.reset(); } });
                                }}
                            >
                                <TextField label="Name" value={form.data.name} onChange={(e) => { form.setData('name', e.target.value); }} {...(form.errors.name === undefined ? {} : { error: form.errors.name })} />
                                <TextField label="Email" type="email" inputMode="email" placeholder="name@business.ng" value={form.data.email} onChange={(e) => { form.setData('email', e.target.value); }} {...(form.errors.email === undefined ? {} : { error: form.errors.email })} />
                                <SelectField label="Role" value={form.data.role} onChange={(e) => { form.setData('role', e.target.value); }}>
                                    {roles.map((r) => (
                                        <option key={r.value} value={r.value}>
                                            {r.label}
                                        </option>
                                    ))}
                                </SelectField>
                                <Button type="submit" variant="primary" size="field" busy={form.processing}>
                                    Send invitation
                                </Button>
                            </form>
                        </section>
                    ) : (
                        <p className="rounded-card border border-rule bg-raised p-6 text-ui text-muted">Only the owner can change who has access.</p>
                    )}
                    <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <h2 className="font-display text-display-s text-ink">Roles</h2>
                        <dl className="mt-3 flex flex-col gap-3 text-ui">
                            <div>
                                <dt className="font-extrabold text-ink">Owner</dt>
                                <dd className="text-muted">Everything, including who has access. One per business.</dd>
                            </div>
                            {roles.map((r) => (
                                <div key={r.value}>
                                    <dt className="font-extrabold text-ink">{r.label}</dt>
                                    <dd className="text-muted">{r.can}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                </div>
            </div>
        </PortalShell>
    );
}
