import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { EnumerateShell } from '@/components/EnumerateShell';
import type { EnumerateFrame } from '@/lib/enumerate';
import type { TeamMember } from '@/lib/organisation';

interface Props {
    frame: EnumerateFrame;
    team: TeamMember[];
    roles: Record<string, string>;
    me: number;
}

const WHAT: Record<string, string> = {
    admin: 'Everything, including the team and the wallet',
    project_lead: 'Checks, bulk, projects and funding the wallet',
    requester: 'Checks and bulk verification',
    viewer: 'Sees everything, buys nothing',
};

/**
 * Team & roles. An admin invites by email; the seat waits for that
 * person to sign in and accept. Removing a seat keeps everything its holder
 * did on the record, and the last admin cannot be removed.
 */
export default function Team({ frame, team, roles, me }: Props) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const canManage = frame.organisation?.can.team === true;
    const invite = useForm({ email: '', role: 'requester' });

    return (
        <EnumerateShell current="team" frame={frame} title="Team & roles">
            <Head title="Team & roles" />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
                <section className="overflow-hidden rounded-card border border-rule bg-raised">
                    <ul>
                        {team.map((m) => (
                            <li key={m.id} className="flex flex-wrap items-center gap-3 border-b border-rule px-5 py-4 last:border-b-0">
                                <span className="min-w-0 flex-1">
                                    <span className="block text-ui font-bold text-ink">
                                        {m.name}
                                        {m.id === me && <span className="font-normal text-muted"> · you</span>}
                                    </span>
                                    <span className="block text-table text-muted">{m.pending ? 'Invited, not yet accepted' : WHAT[m.role]}</span>
                                </span>
                                {canManage && m.id !== me ? (
                                    <>
                                        <select
                                            value={m.role}
                                            aria-label={`Role for ${m.name}`}
                                            onChange={(e) => { router.post(`/enumerate/organisation/team/${String(m.id)}/role`, { role: e.target.value }, { preserveScroll: true }); }}
                                            className="h-9 rounded-sm border border-rule-strong bg-raised px-2 text-table"
                                        >
                                            {Object.entries(roles).map(([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ))}
                                        </select>
                                        <Button variant="quiet" onClick={() => { router.post(`/enumerate/organisation/team/${String(m.id)}/revoke`, {}, { preserveScroll: true }); }}>
                                            Remove
                                        </Button>
                                    </>
                                ) : (
                                    <span className="rounded-full bg-sunken px-2.5 py-0.5 text-[0.75rem] font-bold text-ink">{m.roleLabel}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                {canManage ? (
                    <form
                        className="flex flex-col gap-3 rounded-card border border-rule bg-raised px-5 py-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            invite.post('/enumerate/organisation/team', { preserveScroll: true, onSuccess: () => { invite.reset('email'); } });
                        }}
                    >
                        <h2 className="text-body font-extrabold text-ink">Invite somebody</h2>
                        <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                            Their email
                            <input
                                type="email"
                                inputMode="email"
                                value={invite.data.email}
                                onChange={(e) => { invite.setData('email', e.target.value); }}
                                placeholder="name@company.ng"
                                className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal"
                            />
                        </label>
                        <label className="flex flex-col gap-1.5 text-table font-bold text-ink">
                            Role
                            <select value={invite.data.role} onChange={(e) => { invite.setData('role', e.target.value); }} className="h-11 rounded-sm border border-rule-strong bg-raised px-3 text-ui font-normal">
                                {Object.entries(roles).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}: {WHAT[key]}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {(errors.email ?? errors.phone) !== undefined && <p role="alert" className="text-ui font-semibold text-alert-ink">{errors.email ?? errors.phone}</p>}
                        <Button type="submit" variant="primary" busy={invite.processing} disabled={invite.data.email.trim() === ''}>
                            Invite
                        </Button>
                        <p className="text-[0.75rem] text-muted">We email them. They sign in to Enumerate with that email and accept.</p>
                    </form>
                ) : (
                    <p className="max-w-none rounded-card border border-rule bg-raised px-5 py-5 text-ui text-muted">Only an admin of the organisation changes the team.</p>
                )}
            </div>
        </EnumerateShell>
    );
}
