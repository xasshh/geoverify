import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { DoorTasks, PortalDoor } from '@/components/PortalDoor';

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
 *
 * What changed at M9 is the frame, not the form: this used to render inside the
 * portal's own shell, which made the first screen of the product look like the
 * fourth. See PortalDoor.
 */
export default function SignIn() {
    const form = useForm({ phone: '' });

    return (
        <PortalDoor heading="Sign in, or start an account" aside={<DoorTasks />}>
            <Head title="Sign in" />

            <p className="mt-3 text-body text-muted">
                Enter the phone number for the business. We send a six digit code to it. There is
                no password to remember and nothing to set up first.
            </p>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/portal/sign-in');
                }}
                className="mt-9 flex flex-col gap-6"
            >
                <TextField
                    label="Phone number"
                    name="phone"
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    placeholder="0803 123 4567"
                    size="field"
                    hint="Nigerian mobile number, with or without the spaces."
                    value={form.data.phone}
                    onChange={(event) => {
                        form.setData('phone', event.target.value);
                    }}
                    {...(form.errors.phone !== undefined && { error: form.errors.phone })}
                />

                <Button
                    type="submit"
                    variant="primary"
                    size="field"
                    fullWidth
                    busy={form.processing}
                >
                    {form.processing ? 'Sending' : 'Send me a code'}
                </Button>
            </form>

            <p className="mt-5 flex items-start gap-2.5 text-label text-faint">
                <svg
                    width="16"
                    height="16"
                    viewBox="0 0 16 16"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.4"
                    aria-hidden="true"
                    className="mt-px shrink-0"
                >
                    <rect x="3" y="7" width="10" height="6.5" rx="1" />
                    <path d="M5.4 7V5.1a2.6 2.6 0 0 1 5.2 0V7" />
                </svg>
                The code expires in ten minutes and can be used once.
            </p>

            <div className="mt-10 border-t border-rule pt-6">
                <p className="text-body font-medium text-ink">Business not on the register?</p>
                <p className="mt-1 text-ui text-muted">
                    You can add it yourself after signing in. It takes about three minutes and puts
                    the business on the register as <span className="text-graphite">Listed</span>.
                </p>
            </div>

            <p className="mt-4 max-w-[46ch] text-ui text-faint">
                If an officer recorded your business, use the number you gave them. That is the
                fastest way to prove the listing is yours.
            </p>
        </PortalDoor>
    );
}
