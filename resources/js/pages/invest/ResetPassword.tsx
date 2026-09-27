import { Head, useForm } from '@inertiajs/react';
import { InvestorAuthLayout } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';

/** Choose a new password from the emailed link. */
export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    return (
        <InvestorAuthLayout panel={<div className="mt-auto" />}>
            <Head title="Choose a new password" />
            <h1 className="font-display text-display-l text-ink">Choose a new password</h1>
            <form
                className="mt-6 flex flex-col gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/invest/reset-password');
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
                <TextField
                    label="New password"
                    type="password"
                    size="field"
                    hint="At least ten characters."
                    value={form.data.password}
                    onChange={(e) => {
                        form.setData('password', e.target.value);
                    }}
                    {...(form.errors.password === undefined ? {} : { error: form.errors.password })}
                />
                <TextField
                    label="New password again"
                    type="password"
                    size="field"
                    value={form.data.password_confirmation}
                    onChange={(e) => {
                        form.setData('password_confirmation', e.target.value);
                    }}
                />
                <Button type="submit" variant="primary" size="field-primary" fullWidth busy={form.processing}>
                    Save new password
                </Button>
            </form>
        </InvestorAuthLayout>
    );
}
