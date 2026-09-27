import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { PortalShell } from '@/components/PortalShell';

interface Props {
    account: {
        name: string;
        phone: string;
        email: string | null;
        hasPassword: boolean;
        businessIds: { code: string | null; name: string | null }[];
    };
}

/** Your sign-in: the phone that proves you, an email, and a password for the desk. */
export default function Settings({ account }: Props) {
    const accountName = usePage().props.auth.portal?.name ?? account.name;
    const email = useForm({ email: account.email ?? '' });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    return (
        <PortalShell accountName={accountName} width="page" title="Settings" subtitle="How you sign in">
            <Head title="Settings" />
            <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
                <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                    <h2 className="font-display text-display-s text-ink">Your sign-in</h2>
                    <dl className="mt-4 flex flex-col">
                        <div className="flex justify-between gap-4 border-b border-rule py-3">
                            <dt className="text-ui text-muted">Phone</dt>
                            <dd className="text-ui font-bold text-ink">{account.phone}</dd>
                        </div>
                        {account.businessIds.map((b) => (
                            <div key={b.code} className="flex justify-between gap-4 border-b border-rule py-3 last:border-b-0">
                                <dt className="text-ui text-muted">Business ID · {b.name}</dt>
                                <dd className="numeric-mono text-mono font-medium text-ink">{b.code}</dd>
                            </div>
                        ))}
                    </dl>
                    <form
                        className="mt-5 flex flex-col gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            email.post('/portal/settings/email', { preserveScroll: true });
                        }}
                    >
                        <TextField
                            label="Email"
                            type="email"
                            hint="For receipts, and to sign in with a password."
                            value={email.data.email}
                            onChange={(e) => {
                                email.setData('email', e.target.value);
                            }}
                            {...(email.errors.email === undefined ? {} : { error: email.errors.email })}
                        />
                        <div>
                            <Button type="submit" variant="secondary" size="field" busy={email.processing}>
                                Save email
                            </Button>
                        </div>
                    </form>
                </section>

                <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                    <h2 className="font-display text-display-s text-ink">{account.hasPassword ? 'Change password' : 'Set a password'}</h2>
                    <p className="mt-1 text-ui text-muted">
                        With a password you can sign in with your business ID or email. A code by SMS always works too.
                    </p>
                    <form
                        className="mt-5 flex flex-col gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            password.post('/portal/settings/password', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    password.reset();
                                },
                            });
                        }}
                    >
                        {account.hasPassword && (
                            <TextField
                                label="Current password"
                                type="password"
                                autoComplete="current-password"
                                value={password.data.current_password}
                                onChange={(e) => {
                                    password.setData('current_password', e.target.value);
                                }}
                                {...(password.errors.current_password === undefined ? {} : { error: password.errors.current_password })}
                            />
                        )}
                        <TextField
                            label="New password"
                            type="password"
                            autoComplete="new-password"
                            hint="At least ten characters."
                            value={password.data.password}
                            onChange={(e) => {
                                password.setData('password', e.target.value);
                            }}
                            {...(password.errors.password === undefined ? {} : { error: password.errors.password })}
                        />
                        <TextField
                            label="New password again"
                            type="password"
                            autoComplete="new-password"
                            value={password.data.password_confirmation}
                            onChange={(e) => {
                                password.setData('password_confirmation', e.target.value);
                            }}
                        />
                        <div>
                            <Button type="submit" variant="primary" size="field" busy={password.processing}>
                                Save password
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </PortalShell>
    );
}
