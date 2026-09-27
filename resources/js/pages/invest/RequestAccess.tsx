import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { SelectField, TextField } from '@/components/Field';
import { GeoVerifyLockup } from '@/components/GeoVerifyMark';

/**
 * An organisation asks to use the investor portal. The overview opens at once;
 * everything that names a business waits for KYC.
 */
export default function RequestAccess({ kinds }: { kinds: { value: string; label: string }[] }) {
    const form = useForm({
        organisation: '',
        kind: kinds[0]?.value ?? 'fund',
        website: '',
        name: '',
        title: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const field = (key: keyof typeof form.data, label: string, type = 'text', hint?: string) => (
        <TextField
            label={label}
            type={type}
            value={form.data[key]}
            onChange={(e) => {
                form.setData(key, e.target.value);
            }}
            {...(hint === undefined ? {} : { hint })}
            {...(form.errors[key] === undefined ? {} : { error: form.errors[key] })}
        />
    );

    return (
        <div data-mode="daylight" className="min-h-dvh bg-surface text-ink">
            <Head title="Request investor access" />
            <header className="border-b border-rule bg-raised">
                <div className="mx-auto flex h-[72px] max-w-5xl items-center px-5">
                    <Link href="/invest/sign-in">
                        <GeoVerifyLockup size={36} caption="Investor & discovery" />
                    </Link>
                </div>
            </header>
            <main className="mx-auto max-w-2xl px-5 pt-10 pb-24">
                <div className="rounded-card border border-rule bg-raised px-6 py-8 shadow-card sm:px-9">
                    <p className="text-label font-extrabold tracking-[0.05em] text-gold uppercase">Investor access</p>
                    <h1 className="mt-1 font-display text-display-l text-ink">Request access</h1>
                    <p className="mt-2 text-body text-muted">
                        You can explore verified businesses by state and sector as soon as you
                        submit. Dossiers, data rooms and commissioned verifications open once we
                        have verified your organisation.
                    </p>
                    <form
                        className="mt-7 flex flex-col gap-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/invest/request-access');
                        }}
                    >
                        <h2 className="font-display text-display-s text-ink">Your organisation</h2>
                        {field('organisation', 'Organisation name')}
                        <SelectField
                            label="Kind of investor"
                            value={form.data.kind}
                            onChange={(e) => {
                                form.setData('kind', e.target.value);
                            }}
                        >
                            {kinds.map((k) => (
                                <option key={k.value} value={k.value}>
                                    {k.label}
                                </option>
                            ))}
                        </SelectField>
                        {field('website', 'Website', 'url', 'Optional. It helps us verify you faster.')}

                        <h2 className="mt-3 font-display text-display-s text-ink">You</h2>
                        <div className="grid gap-5 sm:grid-cols-2">
                            {field('name', 'Your name')}
                            {field('title', 'Your role', 'text', 'For example Analyst or Partner')}
                        </div>
                        {field('email', 'Work email', 'email')}
                        <div className="grid gap-5 sm:grid-cols-2">
                            {field('password', 'Password', 'password', 'At least ten characters')}
                            {field('password_confirmation', 'Password again', 'password')}
                        </div>
                        <Button type="submit" variant="primary" size="field" busy={form.processing}>
                            Request access
                        </Button>
                    </form>
                </div>
            </main>
        </div>
    );
}
