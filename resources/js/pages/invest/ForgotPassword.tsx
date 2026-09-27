import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { InvestorAuthLayout } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';

/** A reset link by email. The same answer whether or not the address has an account. */
export default function ForgotPassword() {
    const status = usePage().props.flash.status;
    const form = useForm({ email: '' });

    return (
        <InvestorAuthLayout panel={<div className="mt-auto" />}>
            <Head title="Reset your password" />
            <Link href="/invest/sign-in" className="text-ui font-bold text-muted hover:text-ink">
                <span aria-hidden="true">←</span> Back to sign in
            </Link>
            <h1 className="mt-6 font-display text-display-l text-ink">Reset your password</h1>
            <p className="mt-1.5 text-body text-muted">We email a link to choose a new one. It works for 60 minutes.</p>
            {status !== null && (
                <p role="status" className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                    {status}
                </p>
            )}
            <form
                className="mt-6 flex flex-col gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/invest/forgot-password');
                }}
            >
                <TextField
                    label="Work email"
                    type="email"
                    size="field"
                    value={form.data.email}
                    onChange={(e) => {
                        form.setData('email', e.target.value);
                    }}
                    {...(form.errors.email === undefined ? {} : { error: form.errors.email })}
                />
                <Button type="submit" variant="primary" size="field-primary" fullWidth busy={form.processing}>
                    Email me a link
                </Button>
            </form>
        </InvestorAuthLayout>
    );
}
