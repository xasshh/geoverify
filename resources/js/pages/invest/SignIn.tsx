import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { InvestorAuthLayout, OrRule } from '@/components/AuthLayouts';
import { Button } from '@/components/Button';
import { STATE_TILES } from '@/lib/stateTiles';
import { cx } from '@/lib/cx';

interface Props {
    coverage: { states: { state: string; verified: number }[]; statesCovered: number };
    featured: { score: number; sector: string; state: string | null; lastVerified: string | null } | null;
}

const INPUT =
    'h-[52px] w-full rounded-sm border border-rule-strong bg-raised px-4 text-body text-ink placeholder:text-faint focus:border-gold focus:ring-4 focus:ring-gold-soft focus:outline-none';

/**
 * Investor sign in, as the login mockup draws it: SSO first, email and
 * password under it, the way to ask for access, and beside it the live
 * register rather than a picture of one.
 */
export default function SignIn({ coverage, featured }: Props) {
    const status = usePage().props.flash.status;
    const form = useForm({ email: '', password: '', remember: false });

    return (
        <InvestorAuthLayout panel={<Panel coverage={coverage} featured={featured} />}>
            <Head title="Investor sign in" />

            <h1 className="font-display text-display-l text-ink">Investor sign in</h1>
            <p className="mt-1.5 text-body text-muted">Access verified deal flow, verification reports and data rooms.</p>

            {status !== null && (
                <p role="status" className="mt-5 rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">
                    {status}
                </p>
            )}

            <Link
                href="/invest/sso"
                className="mt-6 flex min-h-[52px] items-center justify-center gap-2.5 rounded-sm bg-ink text-ui font-extrabold text-inverse hover:bg-[#1a2824]"
            >
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                    <path d="M4 21V8l8-5 8 5v13M9 21v-6h6v6M4 21h16" />
                </svg>
                Continue with company SSO
            </Link>

            <div className="my-6">
                <OrRule label="or with email" />
            </div>

            <form
                className="flex flex-col gap-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/invest/sign-in');
                }}
            >
                <label className="flex flex-col gap-2">
                    <span className="text-ui font-bold text-ink">Work email</span>
                    <input
                        className={cx(INPUT, form.errors.email !== undefined && 'border-alert')}
                        type="email"
                        autoComplete="username"
                        placeholder="you@firm.com"
                        value={form.data.email}
                        onChange={(e) => {
                            form.setData('email', e.target.value);
                        }}
                    />
                </label>
                <label className="flex flex-col gap-2">
                    <span className="flex items-baseline justify-between">
                        <span className="text-ui font-bold text-ink">Password</span>
                        <Link href="/invest/forgot-password" className="text-ui font-extrabold text-gold hover:text-gold-dark">
                            Forgot password?
                        </Link>
                    </span>
                    <input
                        className={INPUT}
                        type="password"
                        autoComplete="current-password"
                        value={form.data.password}
                        onChange={(e) => {
                            form.setData('password', e.target.value);
                        }}
                    />
                </label>
                {form.errors.email !== undefined && (
                    <p className="rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{form.errors.email}</p>
                )}
                <Button type="submit" variant="primary" size="field-primary" fullWidth busy={form.processing}>
                    Sign in
                </Button>
            </form>

            <div className="mt-6 flex gap-3.5 rounded-[14px] border border-rule bg-surface px-5 py-4">
                <svg className="mt-0.5 shrink-0 text-gold" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                    <path d="M5.5 10.5h13v10h-13zM8.5 10.5V7a3.5 3.5 0 0 1 7 0v3.5" />
                </svg>
                <p className="text-ui text-muted">
                    <span className="block font-extrabold text-ink">New investor?</span>
                    Accounts are opened after a short KYC check on you and your firm.{' '}
                    <Link href="/invest/request-access" className="font-extrabold text-gold hover:text-gold-dark">
                        Request access
                    </Link>
                </p>
            </div>
        </InvestorAuthLayout>
    );
}

function Panel({ coverage, featured }: Props) {
    const byName = new Map(coverage.states.map((s) => [s.state.toLowerCase(), s.verified]));
    const max = Math.max(1, ...coverage.states.map((s) => s.verified));
    const size = 36;
    const gap = 4;

    const fill = (name: string) => {
        const v = byName.get(name.toLowerCase()) ?? (name === 'Federal Capital Territory' ? byName.get('fct') : undefined) ?? 0;

        if (v <= 0) {
            return 'bg-white/[0.07]';
        }

        const step = v / max;

        return step > 0.75 ? 'bg-[#4DB8B0]' : step > 0.5 ? 'bg-[#3A9C94]' : step > 0.25 ? 'bg-[#2E7C75]' : 'bg-[#255F59]';
    };

    return (
        <>
            <span className="self-start rounded-full border border-white/25 px-3.5 py-1.5 text-table font-bold tracking-[0.05em] uppercase">
                Global investor &amp; discovery portal
            </span>
            <h2 className="mt-7 max-w-[23ch] font-display text-[2.75rem] leading-[1.06] font-extrabold tracking-[-0.03em]">
                Invest in businesses you can see on the ground.
            </h2>

            <div className="mt-10 flex flex-wrap items-end gap-10">
                <div aria-hidden="true" className="relative shrink-0" style={{ width: 8 * size + 7 * gap, height: 7 * size + 6 * gap }}>
                    {STATE_TILES.map((t) => (
                        <span
                            key={t.code}
                            title={t.name}
                            className={cx('absolute rounded-[7px]', fill(t.name))}
                            style={{ width: size, height: size, left: t.col * (size + gap), top: t.row * (size + gap) }}
                        />
                    ))}
                </div>
                <div className="pb-2">
                    <p className="text-table font-bold tracking-[0.05em] text-inverse/60 uppercase">Coverage</p>
                    <p className="mt-1 text-body font-extrabold">
                        {coverage.statesCovered} {coverage.statesCovered === 1 ? 'state' : 'states'} with field-verified businesses
                    </p>
                    <p className="mt-1 max-w-[26ch] text-table text-inverse/65">Brighter tiles have more field-verified businesses.</p>
                </div>
            </div>

            {featured !== null && (
                <div className="mt-10 flex items-center gap-5 rounded-[18px] bg-raised p-5 text-ink">
                    <span className="relative flex size-16 shrink-0 items-center justify-center">
                        <svg className="absolute inset-0 -rotate-90" viewBox="0 0 64 64" aria-hidden="true">
                            <circle cx="32" cy="32" r="28" fill="none" strokeWidth="6" className="stroke-sunken" />
                            <circle
                                cx="32"
                                cy="32"
                                r="28"
                                fill="none"
                                strokeWidth="6"
                                strokeLinecap="round"
                                strokeDasharray={2 * Math.PI * 28}
                                strokeDashoffset={2 * Math.PI * 28 * (1 - featured.score / 100)}
                                className="stroke-gold"
                            />
                        </svg>
                        <span className="text-body font-extrabold">{featured.score}</span>
                    </span>
                    <span className="flex flex-col">
                        <span className="text-body font-extrabold">A verified business in {featured.state ?? 'Nigeria'}</span>
                        <span className="text-ui text-muted">
                            {featured.sector}
                            {featured.lastVerified !== null &&
                                ` · verified ${new Date(featured.lastVerified).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}`}
                        </span>
                        <span className="text-table font-bold text-held-ink">Named once you are verified</span>
                    </span>
                </div>
            )}
        </>
    );
}
