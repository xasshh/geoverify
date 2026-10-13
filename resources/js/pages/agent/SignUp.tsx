import { useState } from 'react';
import type { SubmitEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { TextField } from '@/components/Field';
import { CityBackdrop } from '@/components/CityBackdrop';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

/**
 * Signing up as an agent: a name, an email and a password, and the
 * application opens straight away. This makes an applicant, not staff; the
 * field portal's work unlocks once an administrator approves the application.
 */
export default function SignUp() {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        person_name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: SubmitEventHandler<HTMLFormElement> = (event) => {
        event.preventDefault();
        post('/agent/sign-up');
    };

    return (
        <div data-mode="daylight" className="relative flex min-h-dvh items-center justify-center px-4 py-8 sm:px-6 sm:py-12">
            <Head title="Sign up as an agent" />
            <CityBackdrop />

            <div className="relative w-full max-w-sm">
                <div className="rounded-card bg-raised shadow-[0_18px_48px_rgb(15_26_23/0.34)]">
                    <div className="flex flex-col items-center px-6 pt-7 pb-5 text-center">
                        <a href="/" aria-label="GeoVerify home">
                            <GeoVerifyMark size={56} className="mb-3" title="GeoVerify" />
                        </a>
                        <h1 className="font-display text-[1.5rem] leading-tight font-extrabold text-ink">Sign up as an agent</h1>
                        <p className="mt-2 text-ui text-muted">
                            Open your account, then fill in a short application. Your work unlocks once we approve it.
                        </p>
                    </div>

                    <div className="mx-6 border-t border-rule" />

                    <form onSubmit={submit} className="flex flex-col gap-4 px-6 py-6">
                        <TextField
                            label="Your full name"
                            autoComplete="name"
                            autoFocus
                            required
                            value={data.person_name}
                            onChange={(e) => { setData('person_name', e.target.value); }}
                            {...(errors.person_name === undefined ? {} : { error: errors.person_name })}
                        />
                        <TextField
                            label="Email"
                            type="email"
                            autoComplete="email"
                            required
                            value={data.email}
                            onChange={(e) => { setData('email', e.target.value); }}
                            {...(errors.email === undefined ? {} : { error: errors.email })}
                        />
                        <TextField
                            label="Password (at least 10 characters)"
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="new-password"
                            required
                            value={data.password}
                            onChange={(e) => { setData('password', e.target.value); }}
                            {...(errors.password === undefined ? {} : { error: errors.password })}
                        />
                        <div className="flex flex-col gap-1.5">
                            <TextField
                                label="Type the password again"
                                type={showPassword ? 'text' : 'password'}
                                autoComplete="new-password"
                                required
                                value={data.password_confirmation}
                                onChange={(e) => { setData('password_confirmation', e.target.value); }}
                            />
                            <button
                                type="button"
                                onClick={() => { setShowPassword((v) => !v); }}
                                className="self-start text-label text-muted underline underline-offset-2 hover:text-ink"
                            >
                                {showPassword ? 'Hide passwords' : 'Show passwords'}
                            </button>
                        </div>

                        <Button type="submit" variant="primary" size="field" busy={processing} fullWidth>
                            Sign up
                        </Button>
                        <p className="text-label text-muted">
                            Keep this password: once you are approved, it is the one you sign in to your work with.
                        </p>
                    </form>
                </div>

                <p className="mt-4 text-center text-ui text-white/90">
                    Already signed up?{' '}
                    <Link href="/agent" className="font-extrabold text-white underline underline-offset-2">
                        Sign in
                    </Link>
                </p>
            </div>
        </div>
    );
}
