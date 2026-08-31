import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { CityBackdrop } from '@/components/CityBackdrop';
import { TextField } from '@/components/Field';
import { GeoVerifyMark } from '@/components/GeoVerifyMark';

/**
 * A commissioning client signs in.
 *
 * Email and password, not the portal's phone code. The audience is a named
 * administrator at an agency, not somebody proving they hold a number an
 * officer wrote on a doorstep. No self sign up, and the page says so, because
 * an account is created alongside the organisation.
 */
export default function SignIn() {
    const form = useForm({ email: '', password: '', remember: false });

    return (
        <div
            data-mode="daylight"
            className="relative flex min-h-dvh items-center justify-center px-4 py-8 text-ink sm:px-6 sm:py-12"
        >
            <Head title="Sign in" />

            <CityBackdrop />

            <div className="relative w-full max-w-sm">
                <div className="rounded-md bg-surface shadow-[0_18px_48px_rgb(14_30_46/0.34)]">
                    <div className="flex flex-col items-center px-6 pt-7 pb-5 text-center sm:pt-8 sm:pb-6">
                        <GeoVerifyMark size={64} className="mb-3 sm:mb-4" title="GeoVerify" />
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            Nigeria Business Directory
                        </p>
                        <h1 className="font-display text-display-m text-ink">GeoVerify</h1>
                    </div>

                    {/* A hairline, not a keyline round the whole card. On a
                        photograph a hard border reads as a cutout; the shadow
                        does the separating. */}
                    <div className="mx-6 border-t border-rule" />

                    <form
                        className="flex flex-col gap-4 px-6 py-6"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/client/sign-in');
                        }}
                    >
                        {form.errors.email !== undefined && (
                            <p className="border-l-2 border-alert bg-raised px-3 py-2 text-ui text-alert">
                                {form.errors.email}
                            </p>
                        )}

                        <TextField
                            label="Email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => {
                                form.setData('email', e.target.value);
                            }}
                        />
                        <TextField
                            label="Password"
                            type="password"
                            value={form.data.password}
                            onChange={(e) => {
                                form.setData('password', e.target.value);
                            }}
                        />

                        <label className="flex items-center gap-3 text-ui text-muted">
                            <input
                                type="checkbox"
                                checked={form.data.remember}
                                onChange={(e) => {
                                    form.setData('remember', e.target.checked);
                                }}
                                className="size-5 rounded-[2px] border-rule-strong accent-gold"
                            />
                            Keep me signed in on this device
                        </label>

                        <Button type="submit" variant="primary" size="field" fullWidth busy={form.processing}>
                            Sign in
                        </Button>
                    </form>
                </div>

                <p className="mt-4 text-center numeric-mono text-label text-[#C6CEDA]">
                    Accounts are created by GeoVerify. There is no self sign up.
                </p>
            </div>
        </div>
    );
}
