import { Head, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { PortalShell } from '@/components/PortalShell';
import { TextField } from '@/components/Field';

interface VerifyCodeProps {
    masked: string | null;
}

/**
 * Proving the number.
 *
 * The masked number is shown so a person can see the code went where they
 * expected, and a way back is offered in case it did not. A screen that only
 * says "enter your code" strands anybody who mistyped a digit.
 */
export default function VerifyCode({ masked }: VerifyCodeProps) {
    const form = useForm({ code: '' });

    return (
        <PortalShell kicker="Sign in" title="Enter your code">
            <Head title="Enter your code" />

            <p className="mb-8 max-w-[46ch] text-body text-muted">
                We sent a six digit code to {masked ?? 'your phone'}. It lasts five minutes.
            </p>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/portal/verify');
                }}
                className="flex flex-col gap-6"
            >
                <TextField
                    label="Code"
                    name="code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    placeholder="000000"
                    size="field"
                    machine
                    value={form.data.code}
                    onChange={(event) => {
                        form.setData('code', event.target.value.replace(/\D/g, '').slice(0, 6));
                    }}
                    {...(form.errors.code !== undefined && { error: form.errors.code })}
                />

                <Button type="submit" fullWidth disabled={form.processing || form.data.code.length < 6}>
                    {form.processing ? 'Checking' : 'Continue'}
                </Button>
            </form>

            <button
                type="button"
                onClick={() => {
                    router.get('/portal/sign-in');
                }}
                className="mt-8 text-ui text-muted underline underline-offset-2 hover:text-ink"
            >
                Use a different number
            </button>
        </PortalShell>
    );
}
