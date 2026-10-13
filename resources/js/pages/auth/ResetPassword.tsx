import { useState } from 'react';
import type { SubmitEventHandler } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { FieldAuthCard } from '@/components/FieldAuthCard';

/**
 * Choosing a new password from the emailed link, for staff and approved
 * agents. Fortify checks the token and saves it (ResetUserPassword), then
 * sends them back to /agent to sign in.
 */
export default function ResetPassword({ email, token }: { email: string | null; token: string }) {
    const [show, setShow] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        token,
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit: SubmitEventHandler<HTMLFormElement> = (event) => {
        event.preventDefault();
        post('/reset-password');
    };

    return (
        <FieldAuthCard title="Choose a new password" intro="At least 8 characters. You sign in with it from now on.">
            <Head title="Choose a new password" />
            <form onSubmit={submit} className="flex flex-col gap-4">
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="username"
                    required
                    value={data.email}
                    onChange={(e) => { setData('email', e.target.value); }}
                    {...(errors.email === undefined ? {} : { error: errors.email })}
                />
                <TextField
                    label="New password"
                    type={show ? 'text' : 'password'}
                    autoComplete="new-password"
                    autoFocus
                    required
                    value={data.password}
                    onChange={(e) => { setData('password', e.target.value); }}
                    {...(errors.password === undefined ? {} : { error: errors.password })}
                />
                <div className="flex flex-col gap-1.5">
                    <TextField
                        label="Type it again"
                        type={show ? 'text' : 'password'}
                        autoComplete="new-password"
                        required
                        value={data.password_confirmation}
                        onChange={(e) => { setData('password_confirmation', e.target.value); }}
                    />
                    <button
                        type="button"
                        onClick={() => { setShow((v) => !v); }}
                        className="self-start text-label text-muted underline underline-offset-2 hover:text-ink"
                    >
                        {show ? 'Hide passwords' : 'Show passwords'}
                    </button>
                </div>
                <Button type="submit" variant="primary" size="field" busy={processing} fullWidth>
                    Save my new password
                </Button>
            </form>
        </FieldAuthCard>
    );
}
