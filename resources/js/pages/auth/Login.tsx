import { useState } from 'react';
import type { SubmitEventHandler } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';

interface LoginProps {
    status?: string;
}

export default function Login({ status }: LoginProps) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: SubmitEventHandler<HTMLFormElement> = (event) => {
        event.preventDefault();
        post('/login');
    };

    return (
        <div data-mode="daylight" className="flex min-h-dvh items-center justify-center bg-surface px-6 py-12">
            <Head title="Sign in" />

            <div className="w-full max-w-sm">
                {/* The drawing title block, at the smallest scale it works. */}
                <div className="border-[1.5px] border-ink">
                    <div className="border-b border-ink px-6 pt-6 pb-5">
                        <p className="mb-2 text-label font-semibold tracking-[0.16em] text-gold uppercase">
                            Nigeria Business Directory
                        </p>
                        <h1 className="font-display text-display-m text-ink">GeoVerify</h1>
                    </div>

                    <form onSubmit={submit} className="flex flex-col gap-5 px-6 py-6">
                        {status !== undefined && status !== '' && (
                            <p className="border-l-2 border-green bg-raised px-3 py-2 text-ui text-ink">
                                {status}
                            </p>
                        )}

                        <TextField
                            label="Email"
                            type="email"
                            autoComplete="username"
                            autoFocus
                            required
                            value={data.email}
                            onChange={(e) => {
                                setData('email', e.target.value);
                            }}
                            {...(errors.email === undefined ? {} : { error: errors.email })}
                        />

                        <div className="flex flex-col gap-1.5">
                            <TextField
                                label="Password"
                                type={showPassword ? 'text' : 'password'}
                                autoComplete="current-password"
                                required
                                value={data.password}
                                onChange={(e) => {
                                    setData('password', e.target.value);
                                }}
                                {...(errors.password === undefined ? {} : { error: errors.password })}
                            />
                            <button
                                type="button"
                                onClick={() => {
                                    setShowPassword((v) => !v);
                                }}
                                className="self-start text-label text-muted underline underline-offset-2 hover:text-ink"
                            >
                                {showPassword ? 'Hide password' : 'Show password'}
                            </button>
                        </div>

                        <label className="flex items-center gap-2.5 text-ui text-muted">
                            <input
                                type="checkbox"
                                checked={data.remember}
                                onChange={(e) => {
                                    setData('remember', e.target.checked);
                                }}
                                className="size-4 rounded-[2px] border-rule-strong accent-gold"
                            />
                            Keep me signed in on this device
                        </label>

                        <Button type="submit" variant="primary" size="field" busy={processing} fullWidth>
                            Sign in
                        </Button>
                    </form>
                </div>

                <p className="mt-4 text-center text-label text-faint">
                    Accounts are created by an administrator. There is no self sign up.
                </p>
            </div>
        </div>
    );
}
