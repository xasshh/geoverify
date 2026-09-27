import { Head, useForm } from '@inertiajs/react';
import { BusinessAuthLayout } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';

/** The phone is proved; choose the new password. */
export default function ResetPassword() {
    const form = useForm({ password: '', password_confirmation: '' });

    return (
        <BusinessAuthLayout mobileTitle="Choose a new password" mobileSubtitle="Your number is confirmed.">
            <Head title="Choose a new password" />
            <h1 className="hidden font-display text-display-l text-ink lg:block">Choose a new password</h1>
            <p className="mt-1.5 text-body text-muted">At least ten characters. You can still sign in with a code by SMS at any time.</p>
            <form
                className="mt-6 flex flex-col gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/portal/reset-password');
                }}
            >
                <TextField
                    label="New password"
                    type="password"
                    autoComplete="new-password"
                    size="field"
                    value={form.data.password}
                    onChange={(e) => {
                        form.setData('password', e.target.value);
                    }}
                    {...(form.errors.password === undefined ? {} : { error: form.errors.password })}
                />
                <TextField
                    label="New password again"
                    type="password"
                    autoComplete="new-password"
                    size="field"
                    value={form.data.password_confirmation}
                    onChange={(e) => {
                        form.setData('password_confirmation', e.target.value);
                    }}
                />
                <Button type="submit" variant="primary" size="field-primary" fullWidth busy={form.processing}>
                    Save and sign in
                </Button>
            </form>
        </BusinessAuthLayout>
    );
}
