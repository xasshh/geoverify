import { useState } from 'react';
import type { SubmitEventHandler } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { CityBackdrop } from '@/components/CityBackdrop';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

interface LoginProps {
    /**
     * Null, not undefined, when there is nothing to say.
     *
     * The server sends session('status'), which is null on an ordinary visit.
     * The guard below used to test only for undefined and the empty string, so
     * every load of this page drew an empty status bar above the form.
     */
    status?: string | null;
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
        <div
            data-mode="daylight"
            className="relative flex min-h-dvh items-center justify-center px-4 py-8 sm:px-6 sm:py-12"
        >
            <Head title="Sign in" />

            <CityBackdrop />

            <div className="relative w-full max-w-sm">
                <div className="rounded-md bg-surface shadow-[0_18px_48px_rgb(14_30_46/0.34)]">
                    <div className="flex flex-col items-center px-6 pt-7 pb-5 text-center sm:pt-8 sm:pb-6">
                        <GeoVerifyMark
                            size={64}
                            className="mb-3 sm:mb-4"
                            title="GeoVerify"
                        />
                        <p className="mb-1 text-label font-semibold tracking-[0.16em] text-gold uppercase">
                            Nigeria Business Directory
                        </p>
                        <h1 className="font-display text-display-m text-ink">GeoVerify</h1>
                    </div>

                    {/* A hairline, not the black keyline that used to box the
                        whole card in. On a photograph a hard border reads as a
                        cutout; the shadow does the separating now. */}
                    <div className="mx-6 border-t border-rule" />

                    <form onSubmit={submit} className="flex flex-col gap-5 px-6 py-6">
                        {status !== undefined && status !== null && status !== '' && (
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

                <p className="mt-4 text-center text-label text-[#C6CEDA]">
                    Accounts are created by an administrator. There is no self sign up.
                </p>
            </div>
        </div>
    );
}
