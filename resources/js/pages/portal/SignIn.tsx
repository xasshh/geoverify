import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { PortalShell } from '@/components/PortalShell';
import { TextField } from '@/components/Field';

/**
 * The front door.
 *
 * One field. Not an email, not a password, not a choice between three ways in:
 * a phone number, which is the identity channel this audience actually has and
 * the one an officer already captured at their shop.
 *
 * The same form serves a returning owner and somebody who has never been here.
 * Which of those they are is decided after the number is proved, not before,
 * so nobody is asked to pick "sign in" or "register" about a system they have
 * not used yet.
 */
export default function SignIn() {
    const form = useForm({ phone: '' });

    return (
        <PortalShell kicker="Nigeria Business Directory" title="Sign in">
            <Head title="Sign in" />

            <p className="mb-8 max-w-[46ch] text-body text-muted">
                Enter the phone number for your business. We will send you a code.
            </p>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/portal/sign-in');
                }}
                className="flex flex-col gap-6"
            >
                <TextField
                    label="Phone number"
                    name="phone"
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    placeholder="0803 123 4567"
                    size="field"
                    value={form.data.phone}
                    onChange={(event) => {
                        form.setData('phone', event.target.value);
                    }}
                    {...(form.errors.phone !== undefined && { error: form.errors.phone })}
                />

                <Button type="submit" fullWidth disabled={form.processing}>
                    {form.processing ? 'Sending' : 'Send me a code'}
                </Button>
            </form>

            <p className="mt-8 max-w-[46ch] text-label text-faint">
                If your business was recorded by an officer, use the number you gave them. That is
                the fastest way to prove the listing is yours.
            </p>
        </PortalShell>
    );
}
