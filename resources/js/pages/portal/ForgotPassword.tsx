import { Head, Link, useForm } from '@inertiajs/react';
import { BusinessAuthLayout } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';

/**
 * A password is reset by proving the phone again, not by email: most accounts
 * here have no email, and the phone is the credential the password hangs off.
 */
export default function ForgotPassword() {
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
