import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { TextField } from '@/components/Field';
import { StatusPill } from '@/components/StatusPill';

interface ClientLogin {
    id: number;
    name: string;
    email: string;
    status: string;
    lastSignedInAt: string | null;
}

interface ClientRow {
    id: number;
    name: string;
    shortCode: string;
    contactName: string | null;
    contactEmail: string | null;
    contactPhone: string | null;
    status: string;
    campaignCount: number;
    shortCodeLocked: boolean;
    users: ClientLogin[];
}

interface Props {
    clients: ClientRow[];
}

function blankOrganisation(row: ClientRow | null) {
    return {
        name: row?.name ?? '',
        short_code: row?.shortCode ?? '',
        contact_name: row?.contactName ?? '',
        contact_email: row?.contactEmail ?? '',
        contact_phone: row?.contactPhone ?? '',
    };
}

/** The organisation's details, for adding one or changing one. */
function OrganisationForm({ row, onDone }: { row: ClientRow | null; onDone: () => void }) {
    const form = useForm(blankOrganisation(row));

    const save = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                if (row === null) {
                    form.reset();
                }
                onDone();
            },
        };

        if (row === null) {
            form.post('/admin/clients', options);
        } else {
            form.put(`/admin/clients/${String(row.id)}`, options);
        }
    };

    return (
        <div className="rounded-card border border-rule p-4 bg-raised">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1.4fr_1.6fr_1.2fr]">
                <TextField
                    label="Organisation"
                    value={form.data.name}
                    error={form.errors.name}
                    onChange={(e) => {
                        form.setData('name', e.target.value);
                    }}
                />
                <TextField
                    label="Short code"
                    machine
                    value={form.data.short_code}
                    error={form.errors.short_code}
                    readOnly={row?.shortCodeLocked === true}
                    hint={row?.shortCodeLocked === true ? 'In campaign codes; fixed' : 'Starts every campaign code'}
                    onChange={(e) => {
                        form.setData('short_code', e.target.value.toUpperCase());
                    }}
                />
                <TextField
                    label="Contact person"
                    value={form.data.contact_name}
                    error={form.errors.contact_name}
                    onChange={(e) => {
                        form.setData('contact_name', e.target.value);
                    }}
                />
                <TextField
                    label="Contact email"
                    type="email"
                    value={form.data.contact_email}
                    error={form.errors.contact_email}
                    onChange={(e) => {
                        form.setData('contact_email', e.target.value);
                    }}
                />
                <TextField
                    label="Contact phone"
                    type="tel"
                    value={form.data.contact_phone}
                    error={form.errors.contact_phone}
                    onChange={(e) => {
                        form.setData('contact_phone', e.target.value);
                    }}
                />
            </div>
            <div className="mt-3 flex flex-wrap gap-3">
                <Button
                    variant="primary"
                    onClick={save}
                    busy={form.processing}
                    disabled={form.data.name === '' || form.data.short_code === ''}
                >
                    {row === null ? 'Add client' : 'Save'}
                </Button>
                {row !== null && (
                    <Button variant="quiet" onClick={onDone}>
                        Cancel
                    </Button>
                )}
            </div>
        </div>
    );
}

/** Adds a login at one client. The first password comes back in the flash, once. */
function AddLogin({ client }: { client: ClientRow }) {
    const form = useForm({ name: '', email: '' });

    return (
        <div className="mt-3 flex flex-wrap items-end gap-3">
            <TextField
                label="Name"
                value={form.data.name}
                error={form.errors.name}
                onChange={(e) => {
                    form.setData('name', e.target.value);
                }}
            />
            <TextField
                label="Email"
                type="email"
                value={form.data.email}
                error={form.errors.email}
                onChange={(e) => {
                    form.setData('email', e.target.value);
                }}
            />
            <Button
                onClick={() => {
                    form.post(`/admin/clients/${String(client.id)}/users`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                        },
                    });
                }}
                busy={form.processing}
                disabled={form.data.name === '' || form.data.email === ''}
            >
                Add login
            </Button>
        </div>
    );
}

/**
 * The commissioning clients and the people at them who read their campaigns.
 *
 * A campaign belongs to a client, so a new piece of work starts here. There is
 * no self sign up for clients: a login exists because an administrator added
 * it, and its first password is generated and shown once.
 */
export default function Clients({ clients }: Props) {
    const flash = usePage().props.flash.status;
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <ConsoleShell current="clients">
            <Head title="Clients" />

            <div className="mx-auto max-w-[1200px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">In house</p>
                    <h1 className="font-display text-display-m text-ink">Clients</h1>
                </header>

                {flash !== null && (
                    <p role="status" className="mt-4 rounded-sm bg-green-soft px-4 py-2.5 text-ui text-ink">
                        {flash}
                    </p>
                )}

                <section className="mt-8">
                    <h2 className="font-display text-display-s text-ink">Add a client</h2>
                    <p className="mt-1 mb-3 max-w-[68ch] text-ui text-muted">
                        The body that commissions the work. The short code starts every campaign code for this
                        client (for example <span className="numeric-mono">BNSG-LAN-2026-01</span>) and cannot
                        change once a campaign carries it.
                    </p>
                    <OrganisationForm
                        row={null}
                        onDone={() => {
                            setEditing(null);
                        }}
                    />
                </section>

                <section className="mt-10">
                    <h2 className="font-display text-display-s text-ink">All clients</h2>

                    {clients.length === 0 && (
                        <p className="mt-3 text-ui text-muted">No clients yet. Add the first one above.</p>
                    )}

                    <ul className="mt-3 flex flex-col gap-4">
                        {clients.map((client) => (
                            <li key={client.id} className="rounded-card border border-rule p-4 bg-raised">
                                {editing === client.id ? (
                                    <OrganisationForm
                                        row={client}
                                        onDone={() => {
                                            setEditing(null);
                                        }}
                                    />
                                ) : (
                                    <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                                        <span className="min-w-[200px] flex-1 font-display text-body font-extrabold text-ink">
                                            {client.name}
                                        </span>
                                        <span className="numeric-mono text-label text-faint">{client.shortCode}</span>
                                        <span className="text-label text-muted">
                                            {client.campaignCount} {client.campaignCount === 1 ? 'campaign' : 'campaigns'}
                                        </span>
                                        <StatusPill
                                            tone={client.status === 'active' ? 'accepted' : 'rejected'}
                                            label={client.status === 'active' ? 'Active' : 'Suspended'}
                                            size="sm"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setEditing(client.id);
                                            }}
                                            className="text-label text-gold underline underline-offset-2"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                router.post(
                                                    `/admin/clients/${String(client.id)}/status`,
                                                    { status: client.status === 'active' ? 'suspended' : 'active' },
                                                    { preserveScroll: true },
                                                );
                                            }}
                                            className="text-label text-gold underline underline-offset-2"
                                        >
                                            {client.status === 'active' ? 'Suspend' : 'Reactivate'}
                                        </button>
                                    </div>
                                )}

                                {editing !== client.id &&
                                    (client.contactName !== null || client.contactEmail !== null || client.contactPhone !== null) && (
                                        <p className="mt-1 text-label text-muted">
                                            {[client.contactName, client.contactEmail, client.contactPhone]
                                                .filter((v) => v !== null)
                                                .join(' · ')}
                                        </p>
                                    )}

                                <h3 className="mt-4 text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                    Logins to the client portal
                                </h3>
                                {client.users.length === 0 ? (
                                    <p className="mt-1 text-ui text-faint">
                                        Nobody yet. A client reads their campaigns at /client/sign-in.
                                    </p>
                                ) : (
                                    <ul className="mt-1 flex flex-col border-t border-rule">
                                        {client.users.map((user) => (
                                            <li
                                                key={user.id}
                                                className="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-rule py-2"
                                            >
                                                <span className="min-w-[160px] flex-1 text-ui text-ink">{user.name}</span>
                                                <span className="text-label text-muted">{user.email}</span>
                                                <span className="numeric-mono text-label text-faint">
                                                    {user.lastSignedInAt === null
                                                        ? 'never signed in'
                                                        : `last in ${user.lastSignedInAt.slice(0, 10)}`}
                                                </span>
                                                <StatusPill
                                                    tone={user.status === 'active' ? 'accepted' : 'rejected'}
                                                    label={user.status === 'active' ? 'Active' : 'Suspended'}
                                                    size="sm"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        router.post(
                                                            `/admin/client-users/${String(user.id)}/reset-password`,
                                                            {},
                                                            { preserveScroll: true },
                                                        );
                                                    }}
                                                    className="text-label text-gold underline underline-offset-2"
                                                >
                                                    New password
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        router.post(
                                                            `/admin/client-users/${String(user.id)}/status`,
                                                            { status: user.status === 'active' ? 'suspended' : 'active' },
                                                            { preserveScroll: true },
                                                        );
                                                    }}
                                                    className="text-label text-gold underline underline-offset-2"
                                                >
                                                    {user.status === 'active' ? 'Suspend' : 'Reactivate'}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <AddLogin client={client} />
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </ConsoleShell>
    );
}
