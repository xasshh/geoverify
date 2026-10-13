import type { SubmitEventHandler } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { FieldAuthCard } from '@/components/FieldAuthCard';

/**
 * Forgot password, for anybody who signs in at /agent: staff and approved
 * agents, or people part way through an application. One form; the server
 * decides which account the link is for, and answers the same either way.
 */
export default function ForgotPassword() {
    const status = usePage().props.flash.status;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit: SubmitEventHandler<HTMLFormElement> = (event) => {
        event.preventDefault();
        post('/agent/forgot-password');
    };

    return (
        <FieldAuthCard
            title="Reset your password"
            intro="Enter the email you sign in with. We send a link to it, and you choose a new password."
            below={
                <Link href="/agent" className="font-extrabold text-white underline underline-offset-2">
                    Back to sign in
                </Link>
            }
        >
            <Head title="Reset your password" />
            <form onSubmit={submit} className="flex flex-col gap-5">
                {status !== null && status !== '' && (
                    <p role="status" className="rounded-sm bg-green-soft px-3 py-2 text-ui text-ink">
                        {status}
                    </p>
                )}
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="email"
                    autoFocus
                    required
                    value={data.email}
                    onChange={(e) => { setData('email', e.target.value); }}
                    {...(errors.email === undefined ? {} : { error: errors.email })}
                />
                <Button type="submit" variant="primary" size="field" busy={processing} fullWidth>
                    Send me a link
                </Button>
            </form>
        </FieldAuthCard>
    );
}
