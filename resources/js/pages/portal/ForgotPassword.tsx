import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { TextField } from '@/components/Field';
import { BusinessAuthLayout } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';

/**
 * Forgot password. By email: a link to choose a new one. (With SMS switched
 * on, by proving the phone again instead.)
 */
export default function ForgotPassword({ smsEnabled = false }: { smsEnabled?: boolean }) {
    return smsEnabled ? <ByPhone /> : <ByEmail />;
}

function ByEmail() {
    const status = usePage().props.flash.status;
    const form = useForm({ email: '' });

    return (
        <BusinessAuthLayout mobileTitle="Reset your password" mobileSubtitle="We email you a link.">
            <Head title="Reset your password" />
            <Link href="/portal/sign-in" className="text-ui font-bold text-muted hover:text-ink">
                <span aria-hidden="true">←</span> Back to sign in
            </Link>
            <h1 className="mt-6 hidden font-display text-display-l text-ink lg:block">Reset your password</h1>
            <p className="mt-1.5 text-body text-muted">
                Enter the email on your account. We send a link to it, and you choose a new password.
            </p>
            {status !== null && (
                <p role="status" className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                    {status}
                </p>
            )}
            <form
                className="mt-6 flex flex-col gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/portal/forgot-password');
                }}
            >
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="email"
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
        </BusinessAuthLayout>
    );
}

function ByPhone() {
    const form = useForm({ phone: '', intent: 'reset' });

    return (
        <BusinessAuthLayout mobileTitle="Reset your password" mobileSubtitle="We send a code to the phone on your account.">
            <Head title="Reset your password" />
            <Link href="/portal/sign-in" className="text-ui font-bold text-muted hover:text-ink">
                <span aria-hidden="true">←</span> Back to sign in
            </Link>
            <h1 className="mt-6 hidden font-display text-display-l text-ink lg:block">Reset your password</h1>
            <p className="mt-1.5 text-body text-muted">
                Enter the phone number on your account. We send a code to it, and then you choose a new password.
            </p>
            <form
                className="mt-6 flex flex-col gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((data) => ({ ...data, phone: data.phone.trim().startsWith('+') ? data.phone : `+234${data.phone.replace(/\D/g, '').replace(/^0+/, '').replace(/^234/, '')}` }));
                    form.post('/portal/sign-in');
                }}
            >
                <label className="flex flex-col gap-2">
                    <span className="text-ui font-bold text-ink">Phone number</span>
                    <span className="flex h-[52px] overflow-hidden rounded-sm border border-rule-strong bg-raised focus-within:border-gold focus-within:ring-4 focus-within:ring-gold-soft">
                        <span className="flex items-center border-r border-rule-strong bg-surface px-4 text-body font-extrabold text-ink">+234</span>
                        <input
                            type="tel"
                            inputMode="tel"
                            autoComplete="tel-national"
                            placeholder="803 412 7788"
                            className="min-w-0 flex-1 bg-transparent px-4 text-body text-ink placeholder:text-faint focus:outline-none"
                            value={form.data.phone}
                            onChange={(e) => {
                                form.setData('phone', e.target.value);
                            }}
                        />
                    </span>
                </label>
                {form.errors.phone !== undefined && (
                    <p className="rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{form.errors.phone}</p>
                )}
                <Button type="submit" variant="primary" size="field-primary" fullWidth busy={form.processing}>
                    Send me a code
                </Button>
            </form>
        </BusinessAuthLayout>
    );
}
