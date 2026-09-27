import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { SelectField, TextField } from '@/components/Field';
import { StatusPill } from '@/components/StatusPill';

interface Person {
    id: number;
    name: string;
    email: string;
    role: string;
    status: string;
    staffRef: string | null;
    openAssignments: number;
    captures: number;
}

interface DeviceRow {
    id: number;
    deviceId: string;
    model: string | null;
    appVersion: string | null;
    integrityVerdict: string;
    status: string;
    lastSeenAt: string | null;
    revokedReason: string | null;
    officer: { id: number; name: string; staffRef: string | null } | null;
}

interface Props {
    people: Person[];
    devices: DeviceRow[];
    roles: Array<{ value: string; label: string }>;
}

/** A reason box that appears where it is needed rather than in a dialog. */
function ReasonAction({
    action,
    field,
    label,
    payload,
    prompt,
}: {
    action: string;
    field: string;
    label: string;
    payload: Record<string, string>;
    prompt: string;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ ...payload, [field]: '' });

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => {
                    setOpen(true);
                }}
                className="text-label text-gold underline underline-offset-2"
            >
                {label}
            </button>
        );
    }

    return (
        <div className="mt-2 flex flex-col gap-2">
            <TextField
                label={prompt}
                value={form.data[field]}
                onChange={(e) => {
                    form.setData(field, e.target.value);
                }}
            />
            {Object.values(form.errors).map((error) => (
                <p key={error} className="text-label text-alert">
                    {error}
                </p>
            ))}
            <div className="flex gap-2">
                <Button
                    size="console"
                    onClick={() => {
                        form.post(action, { preserveScroll: true, onSuccess: () => { setOpen(false); } });
                    }}
                    disabled={form.processing}
                >
                    {label}
                </Button>
                <button
                    type="button"
                    onClick={() => {
                        setOpen(false);
                    }}
                    className="text-label text-muted underline underline-offset-2"
                >
                    Cancel
                </button>
            </div>
        </div>
    );
}

/**
 * The staff, and the handsets they carry.
 *
 * One screen because the questions arrive together: somebody has left, so close
 * the account and cut off the phone. Nothing here deletes. A person is
 * suspended and a device is revoked, and both keep everything they recorded
 * attributable to them, which is the whole reason the evidence survives them
 * leaving.
 */
export default function People({ people, devices, roles }: Props) {
    const flash = usePage().props.flash.status;
    const add = useForm({ name: '', email: '', role: 'officer', phone: '' });

    return (
        <ConsoleShell current="people">
            <Head title="People and devices" />

            <div className="mx-auto max-w-[1200px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                        In house
                    </p>
                    <h1 className="font-display text-display-m text-ink">People and devices</h1>
                </header>

                {flash !== null && (
                    <p className="rounded-sm bg-green-soft mt-4 px-4 py-2.5 text-ui text-ink">
                        {flash}
                    </p>
                )}

                <section className="mt-8">
                    <h2 className="font-display text-display-s text-ink">Add somebody</h2>
                    <p className="mt-1 mb-3 max-w-[68ch] text-ui text-muted">
                        The only way onto the staff. There is no self sign up for officers,
                        supervisors or admins, and the first password is generated and shown once
                        so it is never one you chose for them.
                    </p>

                    <div className="flex flex-wrap items-end gap-3 rounded-card border border-rule p-4 bg-raised">
                        <TextField
                            label="Name"
                            value={add.data.name}
                            onChange={(e) => {
                                add.setData('name', e.target.value);
                            }}
                        />
                        <TextField
                            label="Email"
                            type="email"
                            value={add.data.email}
                            onChange={(e) => {
                                add.setData('email', e.target.value);
                            }}
                        />
                        <SelectField
                            label="Role"
                            value={add.data.role}
                            onChange={(e) => {
                                add.setData('role', e.target.value);
                            }}
                        >
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </SelectField>
                        <TextField
                            label="Phone"
                            value={add.data.phone}
                            onChange={(e) => {
                                add.setData('phone', e.target.value);
                            }}
                        />
                        <Button
                            onClick={() => {
                                add.post('/admin/people', {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        add.reset();
                                    },
                                });
                            }}
                            disabled={add.processing}
                        >
                            Add
                        </Button>
                    </div>
                    {Object.values(add.errors).map((error) => (
                        <p key={error} className="mt-2 text-label text-alert">
                            {error}
                        </p>
                    ))}
                </section>

                <section className="mt-10">
                    <h2 className="font-display text-display-s text-ink">Staff</h2>
                    <ul className="mt-3 flex flex-col rounded-card border border-rule px-4 bg-raised">
                        {people.map((person) => (
                            <li
                                key={person.id}
                                className="flex flex-wrap items-start gap-x-6 gap-y-2 border-b border-rule py-3 last:border-b-0"
                            >
                                <div className="min-w-[220px] flex-1">
                                    <p className="text-ui text-ink">{person.name}</p>
                                    <p className="numeric-mono text-label text-faint">
                                        {person.staffRef ?? '.'} &middot; {person.email}
                                    </p>
                                </div>
                                <span className="w-[110px] text-label text-muted">
                                    {person.role}
                                </span>
                                <span className="numeric-mono w-[160px] text-label text-faint">
                                    {person.captures} captures &middot; {person.openAssignments} open
                                </span>
                                <StatusPill
                                    tone={person.status === 'active' ? 'accepted' : 'rejected'}
                                    label={person.status === 'active' ? 'Active' : 'Suspended'}
                                    size="sm"
                                />
                                <div className="w-[240px]">
                                    <ReasonAction
                                        action={`/admin/people/${String(person.id)}/status`}
                                        field="reason"
                                        label={
                                            person.status === 'active' ? 'Suspend' : 'Reinstate'
                                        }
                                        payload={{
                                            status:
                                                person.status === 'active'
                                                    ? 'suspended'
                                                    : 'active',
                                        }}
                                        prompt="Why"
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="mt-10">
                    <h2 className="font-display text-display-s text-ink">Handsets</h2>
                    <p className="mt-1 mb-3 max-w-[68ch] text-ui text-muted">
                        Each carries its own token, so a lost phone is cut off without touching the
                        person's account and they can enrol another one straight away.
                    </p>

                    <ul className="flex flex-col rounded-card border border-rule px-4 bg-raised">
                        {devices.map((device) => (
                            <li
                                key={device.id}
                                className="flex flex-wrap items-start gap-x-6 gap-y-2 border-b border-rule py-3 last:border-b-0"
                            >
                                <div className="min-w-[200px] flex-1">
                                    <p className="numeric-mono text-ui text-ink">
                                        {device.deviceId}
                                    </p>
                                    <p className="text-label text-faint">
                                        {device.model ?? 'unknown model'}
                                        {device.appVersion !== null && ` · ${device.appVersion}`}
                                    </p>
                                </div>
                                <span className="w-[150px] text-label text-muted">
                                    {device.officer?.name ?? 'unassigned'}
                                </span>
                                <span className="w-[110px] text-label text-faint">
                                    {device.integrityVerdict}
                                </span>
                                <StatusPill
                                    tone={device.status === 'active' ? 'accepted' : 'rejected'}
                                    label={device.status === 'active' ? 'Active' : 'Revoked'}
                                    size="sm"
                                />
                                <div className="w-[240px]">
                                    {device.status === 'active' ? (
                                        <ReasonAction
                                            action={`/admin/devices/${String(device.id)}/revoke`}
                                            field="reason"
                                            label="Revoke"
                                            payload={{}}
                                            prompt="Why"
                                        />
                                    ) : (
                                        <p className="text-label text-faint">
                                            {device.revokedReason ?? 'revoked'}
                                        </p>
                                    )}
                                </div>
                            </li>
                        ))}
                        {devices.length === 0 && (
                            <li className="py-4 text-ui text-muted">
                                No handset has been enrolled yet.
                            </li>
                        )}
                    </ul>
                </section>
            </div>
        </ConsoleShell>
    );
}
