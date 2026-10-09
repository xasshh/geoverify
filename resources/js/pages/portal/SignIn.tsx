import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AudienceTabs, BusinessAuthLayout, OrRule } from '@/components/AuthLayouts';
import { GoogleButton } from '@/components/GoogleButton';
import { Button } from '@/components/Button';
import { cx } from '@/lib/cx';

type Audience = 'buyer' | 'business';
type Mode = 'password' | 'code';

const INPUT =
    'h-[52px] w-full rounded-sm border border-rule-strong bg-raised px-4 text-body text-ink placeholder:text-faint focus:border-gold focus:ring-4 focus:ring-gold-soft focus:outline-none';

function startFromUrl(): { audience: Audience; mode: Mode } {
    const params = new URLSearchParams(window.location.search);
    const narrow = window.matchMedia('(max-width: 1023px)').matches;

    // Business by default everywhere: the people this portal serves today run
    // businesses, and buying arrives with the checkout. The phone opens on the
    // code, which is the only way an account starts.
    const audience: Audience = params.get('as') === 'buyer' ? 'buyer' : 'business';

    const mode = params.get('mode') === 'code' || params.get('mode') === 'password'
        ? (params.get('mode') as Mode)
        : narrow || audience === 'buyer'
          ? 'code'
          : 'password';

    return { audience, mode };
}

/**
 * The front door, as the login mockups draw it.
 *
 * Two audiences on one account: somebody buying, and somebody running a
 * business. Two ways in: a code to the phone, which is how every account starts
 * and is always offered, and a business ID or email with a password, for the
 * owner or staff member at a desk who has set one. A business ID is the code
 * on the verification certificate; each person on the business uses it with
 * their own password.
 */
export default function SignIn({ smsEnabled = false }: { smsEnabled?: boolean }) {
    const status = usePage().props.flash.status;
    const [view, setView] = useState(startFromUrl);
    const { audience } = view;
    // With SMS switched off there is one way in: email (or business ID) and password.
    const mode: Mode = smsEnabled ? view.mode : 'password';
    const register = `/portal/register?as=${audience}`;

    const password = useForm({ identifier: '', password: '', remember: false });
    const code = useForm({ phone: '', intent: 'sign-in', audience });

    const setAudience = (next: Audience) => {
        setView({ audience: next, mode: next === 'buyer' && smsEnabled ? 'code' : mode });
        code.setData('audience', next);
    };

    return (
        <BusinessAuthLayout
            topRight={
                <>
                    New to GeoVerify?&nbsp;
                    <Link href={smsEnabled ? '/portal/sign-in?as=business&mode=code' : '/portal/register?as=business'} preserveState={false} className="font-extrabold text-gold hover:text-gold-dark">
                        List your business, free
                    </Link>
                </>
            }
        >
            <Head title="Sign in" />

            <h1 className="hidden font-display text-display-l text-ink lg:block">Welcome back</h1>
            <p className="mt-1.5 hidden text-body text-muted lg:block">
                {audience === 'business'
                    ? 'Sign in to the business portal to manage your listing, orders and payments.'
                    : 'Sign in to follow your orders and the businesses you save.'}
            </p>

            {status !== null && (
                <p role="status" className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                    {status}
                </p>
            )}

            <div className="mt-6">
                <AudienceTabs value={audience} onChange={setAudience} />
            </div>

            {mode === 'password' ? (
                <form
                    className="mt-6 flex flex-col gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        password.post('/portal/sign-in/password');
                    }}
                >
                    <label className="flex flex-col gap-2">
                        <span className="text-ui font-bold text-ink">
                            {audience === 'business' ? 'Business ID or email' : 'Email'}
                        </span>
                        <input
                            className={INPUT}
                            autoComplete="username"
                            placeholder={audience === 'business' ? 'NBD-XXXX-XXXX-X or you@business.ng' : 'you@example.com'}
                            value={password.data.identifier}
                            onChange={(e) => {
                                password.setData('identifier', e.target.value);
                            }}
                            aria-invalid={password.errors.identifier !== undefined || undefined}
                        />
                    </label>
                    <label className="flex flex-col gap-2">
                        <span className="flex items-baseline justify-between">
                            <span className="text-ui font-bold text-ink">Password</span>
                            <Link href="/portal/forgot-password" className="text-ui font-extrabold text-gold hover:text-gold-dark">
                                Forgot password?
                            </Link>
                        </span>
                        <input
                            className={INPUT}
                            type="password"
                            autoComplete="current-password"
                            value={password.data.password}
                            onChange={(e) => {
                                password.setData('password', e.target.value);
                            }}
                        />
                    </label>
                    {password.errors.identifier !== undefined && (
                        <p className="rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{password.errors.identifier}</p>
                    )}
                    <label className="flex items-center gap-3 text-ui text-ink">
                        <input
                            type="checkbox"
                            checked={password.data.remember}
                            onChange={(e) => {
                                password.setData('remember', e.target.checked);
                            }}
                            className="size-5 rounded-[4px] accent-gold"
                        />
                        Keep me signed in on this device
                    </label>
                    <Button type="submit" variant="primary" size="field-primary" fullWidth busy={password.processing}>
                        Sign in
                    </Button>
                    {!smsEnabled && <GoogleButton next="portal" />}
                    {smsEnabled && (
                        <>
                            <OrRule />
                            <Button
                                variant="secondary"
                                size="field-primary"
                                fullWidth
                                onClick={() => {
                                    setView({ audience, mode: 'code' });
                                }}
                            >
                                <PhoneIcon />
                                Get a one-time code by SMS
                            </Button>
                        </>
                    )}
                </form>
            ) : (
                <form
                    className="mt-6 flex flex-col gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        code.transform((data) => ({ ...data, phone: withCountryCode(data.phone) }));
                        code.post('/portal/sign-in');
                    }}
                >
                    <label className="flex flex-col gap-2">
                        <span className="text-ui font-bold text-ink">Phone number</span>
                        <span
                            className={cx(
                                'flex h-[52px] overflow-hidden rounded-sm border bg-raised focus-within:border-gold focus-within:ring-4 focus-within:ring-gold-soft',
                                code.errors.phone === undefined ? 'border-rule-strong' : 'border-alert',
                            )}
                        >
                            <span className="flex items-center border-r border-rule-strong bg-surface px-4 text-body font-extrabold text-ink">
                                +234
                            </span>
                            <input
                                type="tel"
                                inputMode="tel"
                                autoComplete="tel-national"
                                placeholder="803 412 7788"
                                className="min-w-0 flex-1 bg-transparent px-4 text-body text-ink placeholder:text-faint focus:outline-none"
                                value={code.data.phone}
                                onChange={(e) => {
                                    code.setData('phone', e.target.value);
                                }}
                            />
                        </span>
                    </label>
                    {code.errors.phone !== undefined && (
                        <p className="rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{code.errors.phone}</p>
                    )}
                    <Button type="submit" variant="primary" size="field-primary" fullWidth busy={code.processing}>
                        Send me a code
                    </Button>
                    <OrRule />
                    <Button
                        variant="secondary"
                        size="field-primary"
                        fullWidth
                        onClick={() => {
                            setView({ audience, mode: 'password' });
                        }}
                    >
                        Use {audience === 'business' ? 'business ID' : 'email'} and password
                    </Button>
                </form>
            )}

            {audience === 'business' ? (
                <p className="mt-6 flex gap-3 rounded-[14px] bg-gold-soft px-5 py-4 text-ui text-gold-dark">
                    <svg className="mt-0.5 shrink-0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                        <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5 4.5-5" />
                    </svg>
                    <span>
                        {smsEnabled
                            ? 'Staff sign in with the business ID from your verification certificate and their own password. Set yours under Settings after your first sign-in by code.'
                            : 'Staff can also sign in with the business ID from your verification certificate and their own password.'}{' '}
                        {!smsEnabled && (
                            <>
                                New here?{' '}
                                <Link href={register} className="font-extrabold underline underline-offset-2">
                                    Create an account
                                </Link>
                            </>
                        )}
                    </span>
                </p>
            ) : (
                <p className="mt-10 text-center text-ui text-muted">
                    New here?{' '}
                    {smsEnabled ? (
                        <button
                            type="button"
                            onClick={() => {
                                setView({ audience, mode: 'code' });
                            }}
                            className="font-extrabold text-gold hover:text-gold-dark"
                        >
                            Create an account
                        </button>
                    ) : (
                        <Link href={register} className="font-extrabold text-gold hover:text-gold-dark">
                            Create an account
                        </Link>
                    )}
                </p>
            )}
        </BusinessAuthLayout>
    );
}

/** The +234 is shown as a fixed prefix; a leading 0 typed out of habit is dropped. */
function withCountryCode(raw: string): string {
    const digits = raw.replace(/[^\d+]/g, '');

    if (digits.startsWith('+')) {
        return digits;
    }

    return `+234${digits.replace(/^0+/, '').replace(/^234/, '')}`;
}

function PhoneIcon() {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
            <path d="M8 2.5h8a1.5 1.5 0 0 1 1.5 1.5v16a1.5 1.5 0 0 1-1.5 1.5H8A1.5 1.5 0 0 1 6.5 20V4A1.5 1.5 0 0 1 8 2.5zM11 18h2" />
        </svg>
    );
}
