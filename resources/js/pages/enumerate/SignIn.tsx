import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { HexField } from '@/components/AuthLayouts';
import { GoogleButton } from '@/components/GoogleButton';
import { Button } from '@/components/Button';
import { EnumerateLockup } from '@/components/EnumerateShell';
import { cx } from '@/lib/cx';
import { priceLabel, type Prices } from '@/lib/enumerate';

const INPUT =
    'h-[52px] w-full rounded-sm border border-rule-strong bg-raised px-4 text-body text-ink placeholder:text-faint focus:border-gold focus:ring-4 focus:ring-gold-soft focus:outline-none';

type Mode = 'password' | 'code';

/**
 * Enumerate's door, to board 27.
 *
 * The forms post to the portal's own endpoints: these are the same accounts,
 * and a second sign-in implementation would be a second thing to get wrong.
 * The page has already told the session to come back here afterwards. A new
 * person creates an account with their email (or, with SMS switched on,
 * starts with a code to their phone).
 */
export default function SignIn({ prices, smsEnabled = false }: { prices: Prices; smsEnabled?: boolean }) {
    const status = usePage().props.flash.status;
    const google = usePage().props.googleSignIn;
    const [who, setWho] = useState<'individual' | 'organisation'>('individual');
    const [mode, setMode] = useState<Mode>('password');

    const password = useForm({ identifier: '', password: '', remember: true });
    // "buyer" is the portal's word for somebody acting for themselves rather
    // than for a business, which is exactly what a requester is.
    const code = useForm({ phone: '', intent: 'sign-in', audience: 'buyer' });

    const tiers: [string, string, string, number][] = [
        ['Tier 1', 'Registry check', 'CAC + TIN · same day', prices.tier1],
        ['Tier 2', 'Location verification', 'Agent visit + geo-tagged photos', prices.tier2],
        ['Tier 3', 'Daily activity', 'Monitored for up to 30 days', prices.tier3['30'] ?? 0],
    ];

    return (
        <div data-mode="daylight" className="min-h-dvh bg-raised text-ink lg:grid lg:grid-cols-[minmax(0,0.78fr)_minmax(0,1fr)]">
            <Head title="Sign in to Enumerate" />

            <aside className="relative overflow-hidden bg-[#0F1A17] px-6 pt-8 pb-14 text-inverse lg:flex lg:min-h-dvh lg:flex-col lg:px-12 lg:pt-10 lg:pb-10">
                <HexField />
                <div className="relative flex flex-1 flex-col">
                    <EnumerateLockup size={44} tone="light" />

                    <h1 className="mt-10 max-w-[18ch] font-display text-[2rem] leading-[1.08] font-extrabold tracking-[-0.02em] lg:mt-16 lg:text-[2.75rem]">
                        Verify any business in Nigeria, on paper and on the ground.
                    </h1>
                    <p className="mt-4 max-w-[46ch] text-body text-inverse/80">
                        Check CAC and TIN, send an agent to the address, or monitor daily activity for up to 30 days.
                    </p>

                    <ul className="mt-8 hidden max-w-[440px] flex-col gap-3 lg:flex">
                        {tiers.map(([tier, title, body, price]) => (
                            <li key={tier} className="flex items-center gap-4 rounded-card border border-white/10 bg-white/5 px-4 py-3.5">
                                <span className="shrink-0 rounded-[6px] bg-gold-soft px-2 py-1 text-[0.6875rem] font-extrabold tracking-[0.04em] whitespace-nowrap text-gold-dark uppercase">{tier}</span>
                                <span className="flex min-w-0 flex-col">
                                    <span className="text-ui font-extrabold">{title}</span>
                                    <span className="text-table text-inverse/65">{body}</span>
                                </span>
                                <span className="ml-auto font-display text-body font-extrabold text-logo">{priceLabel(price)}</span>
                            </li>
                        ))}
                    </ul>

                    <p className="mt-auto hidden pt-10 text-table text-inverse/60 lg:block">
                        Organisations: custom enumeration projects with your own data fields and a live monitoring portal.
                    </p>
                </div>
            </aside>

            <main className="relative -mt-8 flex min-h-[70dvh] flex-col rounded-t-[28px] bg-raised px-6 pt-7 pb-8 lg:mt-0 lg:min-h-dvh lg:rounded-none lg:px-14 lg:pt-8">
                <div className="hidden justify-end text-ui text-muted lg:flex">
                    Don’t have an account?&nbsp;
                    <button type="button" onClick={() => { setWho('individual'); setMode('code'); }} className="font-extrabold text-gold hover:text-gold-dark">
                        Create an account
                    </button>
                </div>

                <div className="flex flex-1 flex-col lg:items-center lg:justify-center">
                    <div className="w-full lg:max-w-[420px]">
                        <h2 className="font-display text-display-l text-ink">Welcome back</h2>
                        <p className="mt-1.5 text-body text-muted">Sign in to run and track your verifications.</p>

                        {status !== null && (
                            <p role="status" className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                                {status}
                            </p>
                        )}

                        <div role="tablist" aria-label="Who is signing in" className="mt-6 grid grid-cols-2 gap-1 rounded-[14px] bg-sunken p-1.5">
                            {(['individual', 'organisation'] as const).map((key) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={who === key}
                                    onClick={() => { setWho(key); }}
                                    className={cx(
                                        'min-h-touch-lg rounded-[10px] text-ui capitalize transition-colors',
                                        who === key ? 'bg-raised font-extrabold text-ink shadow-card' : 'font-semibold text-muted hover:text-ink',
                                    )}
                                >
                                    {key}
                                </button>
                            ))}
                        </div>

                        {who === 'organisation' ? (
                            <div className="mt-6 rounded-card border border-rule bg-sunken px-5 py-5">
                                <p className="text-body font-extrabold text-ink">Every seat signs in as its own person</p>
                                <p className="mt-2 text-ui text-muted">
                                    Sign in with your own {smsEnabled ? 'number or email' : 'email'}, then choose your organisation from the switcher at the top of the
                                    sidebar. Invited to one? The invitation is waiting when you sign in with the {smsEnabled ? 'number' : 'email'} it was sent to.
                                </p>
                                <p className="mt-3 text-ui text-muted">New here? Sign in, then choose “Open an organisation account”.</p>
                                <button type="button" onClick={() => { setWho('individual'); }} className="mt-4 text-ui font-extrabold text-gold hover:text-gold-dark">
                                    Sign in →
                                </button>
                            </div>
                        ) : mode === 'password' || !smsEnabled ? (
                            <form
                                className="mt-6 flex flex-col gap-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    password.post('/portal/sign-in/password');
                                }}
                            >
                                {google && (
                                    <>
                                        <GoogleButton />
                                        <OrDivider />
                                    </>
                                )}
                                <label className="flex flex-col gap-2">
                                    <span className="text-ui font-bold text-ink">{smsEnabled ? 'Email or phone number' : 'Email'}</span>
                                    <input
                                        className={INPUT}
                                        autoComplete="username"
                                        placeholder="you@email.com"
                                        value={password.data.identifier}
                                        onChange={(e) => { password.setData('identifier', e.target.value); }}
                                        aria-invalid={password.errors.identifier !== undefined || undefined}
                                    />
                                </label>
                                <label className="flex flex-col gap-2">
                                    <span className="flex items-center justify-between text-ui font-bold text-ink">
                                        Password
                                        <Link href="/portal/forgot-password" className="text-table font-extrabold text-gold hover:text-gold-dark">
                                            Forgot password?
                                        </Link>
                                    </span>
                                    <input
                                        type="password"
                                        className={INPUT}
                                        autoComplete="current-password"
                                        placeholder="Your password"
                                        value={password.data.password}
                                        onChange={(e) => { password.setData('password', e.target.value); }}
                                    />
                                </label>
                                {password.errors.identifier !== undefined && (
                                    <p role="alert" className="text-ui font-semibold text-alert-ink">{password.errors.identifier}</p>
                                )}
                                <Button type="submit" variant="primary" size="field-primary" fullWidth busy={password.processing}>
                                    Sign in
                                </Button>
                                {smsEnabled ? (
                                    <Button type="button" size="field-primary" fullWidth onClick={() => { setMode('code'); }}>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                            <path d="M7 2.5h10v19H7zM11 18.5h2" />
                                        </svg>
                                        Sign in with a code by SMS
                                    </Button>
                                ) : (
                                    <p className="text-center text-ui text-muted">
                                        New here?{' '}
                                        <Link href="/portal/register?as=buyer&next=enumerate" className="font-extrabold text-gold hover:text-gold-dark">
                                            Create an account
                                        </Link>
                                    </p>
                                )}
                            </form>
                        ) : (
                            <form
                                className="mt-6 flex flex-col gap-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    code.post('/portal/sign-in');
                                }}
                            >
                                <label className="flex flex-col gap-2">
                                    <span className="text-ui font-bold text-ink">Phone number</span>
                                    <input
                                        className={INPUT}
                                        inputMode="tel"
                                        autoComplete="tel"
                                        placeholder="0803 000 0000"
                                        value={code.data.phone}
                                        onChange={(e) => { code.setData('phone', e.target.value); }}
                                        aria-invalid={code.errors.phone !== undefined || undefined}
                                    />
                                </label>
                                <p className="text-table text-muted">
                                    We send a six-digit code. New here? The same code opens your account.
                                </p>
                                {code.errors.phone !== undefined && (
                                    <p role="alert" className="text-ui font-semibold text-alert-ink">{code.errors.phone}</p>
                                )}
                                <Button type="submit" variant="primary" size="field-primary" fullWidth busy={code.processing}>
                                    Send me a code
                                </Button>
                                <Button type="button" variant="quiet" size="field" fullWidth onClick={() => { setMode('password'); }}>
                                    Use my password instead
                                </Button>
                            </form>
                        )}
                    </div>
                </div>
            </main>
        </div>
    );
}

/** "or" between two ways in. */
function OrDivider() {
    return (
        <p className="flex items-center gap-3 text-table font-semibold text-muted" aria-hidden="true">
            <span className="h-px flex-1 bg-rule" />
            or with your email
            <span className="h-px flex-1 bg-rule" />
        </p>
    );
}
