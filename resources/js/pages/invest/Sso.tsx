import { Head, Link, useForm } from '@inertiajs/react';
import { InvestorAuthLayout } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';

/** Company single sign-on, found by the domain of a work email. */
export default function Sso() {
    const form = useForm({ email: '' });

    return (
        <InvestorAuthLayout panel={<SsoPanel />}>
            <Head title="Company SSO" />
            <Link href="/invest/sign-in" className="text-ui font-bold text-muted hover:text-ink">
                <span aria-hidden="true">←</span> Back to sign in
            </Link>
            <h1 className="mt-6 font-display text-display-l text-ink">Continue with company SSO</h1>
            <p className="mt-1.5 text-body text-muted">Enter your work email and we send you to your firm’s sign-in.</p>
            <form
                className="mt-6 flex flex-col gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/invest/sso');
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
                    Continue
                </Button>
            </form>
        </InvestorAuthLayout>
    );
}

function SsoPanel() {
    return (
        <>
            <h2 className="mt-auto max-w-[18ch] font-display text-[2.5rem] leading-[1.05] font-extrabold tracking-[-0.03em]">
                Your firm’s sign-in, your firm’s rules.
            </h2>
            <p className="mt-4 max-w-[46ch] text-body text-inverse/75">
                Single sign-on is connected per organisation. Ask us to connect yours and your analysts sign in with the
                accounts they already have.
            </p>
            <div className="mt-auto" />
        </>
    );
}
