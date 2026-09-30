import { Head, Link, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/Button';
import { EnumerateShell } from '@/components/EnumerateShell';
import { cx } from '@/lib/cx';
import { companyType, kobo, registration, type EnumerateFrame, type Prices, type RegistryMatch } from '@/lib/enumerate';

interface Props {
    frame: EnumerateFrame;
    prices: Prices;
    start: { by: 'name' | 'rc'; q: string; tier: 1 | 2 | 3 };
}

type Tier = 1 | 2 | 3;

const TIERS: { tier: Tier; title: string; turnaround: string; points: string[]; includes?: string }[] = [
    {
        tier: 1,
        title: 'Registry check',
        turnaround: 'Same day',
        points: ['CAC status, incorporation date and directors', 'TIN matched with FIRS records', 'Name and address consistency check'],
    },
    {
        tier: 2,
        title: 'Location verification',
        turnaround: '24 to 48 hours',
        includes: 'Everything in Tier 1',
        points: ['Agent visits the address', 'Geo-tagged photos of the storefront and signage'],
    },
    {
        tier: 3,
        title: 'Daily activity',
        turnaround: 'Up to 30 days',
        includes: 'Everything in Tier 1 and 2',
        points: ['Repeat agent visits during trading hours', 'Daily log of hours, staff, stock and customers'],
    },
];

const GETS: Record<Tier, string[]> = {
    1: ['Registry result page', 'CAC and TIN findings', 'Directors named on the CAC record'],
    2: ['Registry result page', 'Agent visit with storefront and signage photos', 'Distance from the registered address'],
    3: ['Registry result page', 'Agent photos with the distance from the registered address', 'Daily activity log', 'Interim and final reports'],
};

/** The register's candidates, asked of the server, which asks the provider. */
async function lookup(by: 'name' | 'rc', q: string): Promise<{ matches: RegistryMatch[] } | { message: string }> {
    const response = await fetch(`/enumerate/lookup?${new URLSearchParams({ by, q }).toString()}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        const problem = (await response.json().catch(() => ({}))) as { message?: string };

        return { message: problem.message ?? 'The register did not answer. Try again.' };
    }

    return (await response.json()) as { matches: RegistryMatch[] };
}

/**
 * New verification, to board 29: find the business in the CAC register, choose
 * how deep to check, and pay from the wallet.
 *
 * The business is picked from what the register returned, never typed: the
 * checks are run against the number the register gave, and the name is only
 * what the request is called.
 */
export default function New({ frame, prices, start }: Props) {
    const [by, setBy] = useState(start.by);
    const [q, setQ] = useState(start.q);
    const [searching, setSearching] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [matches, setMatches] = useState<RegistryMatch[] | null>(null);
    const [picked, setPicked] = useState<RegistryMatch | null>(null);
    const ran = useRef(false);

    const form = useForm({ name: '', rc_number: '', company_type: '', address: '', tier: start.tier, days: 30 as 7 | 14 | 30 });

    const search = useCallback(async (searchBy: 'name' | 'rc', term: string) => {
        if (term.trim().length < 3) {
            return;
        }

        setSearching(true);
        setProblem(null);
        setPicked(null);

        const answer = await lookup(searchBy, term.trim()).catch(() => ({ message: 'You seem to be offline. Try again when you have signal.' }));

        setSearching(false);

        if ('message' in answer) {
            setProblem(answer.message);
            setMatches(null);

            return;
        }

        setMatches(answer.matches);

        if (answer.matches.length === 1 && answer.matches[0] !== undefined) {
            setPicked(answer.matches[0]);
        }
    }, []);

    useEffect(() => {
        if (!ran.current && start.q.trim().length >= 3) {
            ran.current = true;
            void search(start.by, start.q);
        }
    }, [search, start.by, start.q]);

    const tier = form.data.tier;
    const price = tier === 1 ? prices.tier1 : tier === 2 ? prices.tier2 : (prices.tier3[String(form.data.days)] ?? 0);
    const after = frame.walletMinor - price;
    const canPay = picked !== null && after >= 0;

    const pay = () => {
        if (picked === null) {
            return;
        }

        form.transform((data) => ({
            ...data,
            name: picked.name,
            rc_number: picked.rcNumber,
            company_type: picked.companyType,
            address: picked.place ?? '',
            days: data.tier === 3 ? data.days : null,
        }));
        form.post('/enumerate/verify', { preserveScroll: true });
    };

    return (
        <EnumerateShell current="new" frame={frame} title="New verification" crumbs={<><Link href="/enumerate" className="hover:text-ink">Home</Link> / New verification</>}>
            <Head title="New verification" />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
                <div className="flex flex-col gap-5">
                    <section className="rounded-card border border-rule bg-raised px-6 py-6">
                        <h2 className="flex items-center gap-3 text-[1.1875rem] font-extrabold text-ink">
                            <span className="flex size-8 items-center justify-center rounded-full bg-gold text-ui text-on-accent">1</span>
                            Find the business
                        </h2>

                        <form
                            className="mt-4 flex flex-col gap-3 sm:flex-row"
                            onSubmit={(e) => {
                                e.preventDefault();
                                void search(by, q);
                            }}
                        >
                            <div role="tablist" aria-label="Search by" className="flex shrink-0 rounded-[10px] bg-sunken p-1">
                                {(
                                    [
                                        ['name', 'Business name'],
                                        ['rc', 'CAC number'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        role="tab"
                                        aria-selected={by === key}
                                        onClick={() => { setBy(key); }}
                                        className={cx('min-h-[40px] rounded-[8px] px-3.5 text-table', by === key ? 'bg-raised font-extrabold text-ink shadow-card' : 'font-semibold text-muted')}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <input
                                aria-label={by === 'name' ? 'Business name' : 'CAC number'}
                                value={q}
                                onChange={(e) => { setQ(e.target.value); }}
                                placeholder={by === 'name' ? 'Kora Build Supplies' : 'RC 1482093'}
                                className="h-12 min-w-0 flex-1 rounded-sm border-2 border-rule-strong bg-raised px-4 text-body text-ink placeholder:text-faint focus:border-gold focus:outline-none"
                            />
                            <Button type="submit" variant="primary" size="field" busy={searching} disabled={q.trim().length < 3}>
                                Search
                            </Button>
                        </form>

                        {problem !== null && <p role="alert" className="mt-4 rounded-sm bg-amber-soft px-4 py-3 text-ui font-semibold text-amber-ink">{problem}</p>}

                        {matches !== null && (
                            <div className="mt-5">
                                <p className="text-[0.6875rem] font-extrabold tracking-[0.06em] text-muted uppercase">
                                    {matches.length === 0 ? 'No matches in the CAC register' : `${String(matches.length)} ${matches.length === 1 ? 'match' : 'matches'} in the CAC register`}
                                </p>
                                <ul className="mt-2 flex flex-col gap-2" role="radiogroup" aria-label="Matches">
                                    {matches.map((m) => {
                                        const on = picked?.rcNumber === m.rcNumber && picked.companyType === m.companyType;

                                        return (
                                            <li key={`${m.companyType}-${m.rcNumber}`}>
                                                <button
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={on}
                                                    onClick={() => { setPicked(m); }}
                                                    className={cx(
                                                        'flex w-full items-center gap-4 rounded-card border px-4 py-3.5 text-left',
                                                        on ? 'border-2 border-gold bg-gold-soft/40' : 'border-rule hover:border-rule-strong',
                                                    )}
                                                >
                                                    <span className={cx('size-5 shrink-0 rounded-full border-2', on ? 'border-gold bg-[radial-gradient(circle,var(--color-gold)_45%,transparent_50%)]' : 'border-rule-strong')} aria-hidden="true" />
                                                    <span className="flex min-w-0 flex-col">
                                                        <span className="text-ui font-extrabold text-ink uppercase">{m.name}</span>
                                                        <span className="text-table text-muted">
                                                            <span className="font-mono">{registration(m.rcNumber, m.companyType)}</span> · {companyType(m.companyType)}
                                                            {m.place !== null && ` · ${m.place}`}
                                                        </span>
                                                    </span>
                                                    {m.status !== null && (
                                                        <span className={cx('ml-auto rounded-full px-2.5 py-1 text-[0.75rem] font-extrabold', m.status === 'Active' ? 'bg-gold-soft text-gold-dark' : 'bg-amber-soft text-amber-ink')}>
                                                            {m.status}
                                                        </span>
                                                    )}
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                                {by === 'name' && matches.length > 0 && (
                                    <p className="mt-3 text-table text-muted">Not the one? Search by its CAC number instead: it is on the company’s letterhead and invoices.</p>
                                )}
                            </div>
                        )}
                    </section>

                    <section className="rounded-card border border-rule bg-raised px-6 py-6">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <h2 className="flex items-center gap-3 text-[1.1875rem] font-extrabold text-ink">
                                <span className="flex size-8 items-center justify-center rounded-full bg-gold text-ui text-on-accent">2</span>
                                How deep should we check?
                            </h2>
                            <p className="max-w-[240px] text-table text-muted">Each tier includes everything in the tiers before it.</p>
                        </div>

                        <div className="mt-5 grid gap-3 md:grid-cols-3" role="radiogroup" aria-label="Tier">
                            {TIERS.map((t) => {
                                const on = tier === t.tier;
                                const amount = t.tier === 1 ? prices.tier1 : t.tier === 2 ? prices.tier2 : (prices.tier3['30'] ?? 0);

                                return (
                                    <button
                                        key={t.tier}
                                        type="button"
                                        role="radio"
                                        aria-checked={on}
                                        onClick={() => { form.setData('tier', t.tier); }}
                                        className={cx('flex flex-col rounded-card border px-4 py-4 text-left', on ? 'border-2 border-gold bg-gold-soft/30' : 'border-rule hover:border-rule-strong')}
                                    >
                                        <span className="flex items-center justify-between text-[0.6875rem] font-extrabold tracking-[0.06em] text-gold-dark uppercase">
                                            Tier {t.tier}
                                            <span className={cx('size-5 rounded-full border-2', on ? 'border-gold bg-[radial-gradient(circle,var(--color-gold)_45%,transparent_50%)]' : 'border-rule-strong')} aria-hidden="true" />
                                        </span>
                                        <span className="mt-2 text-body font-extrabold text-ink">{t.title}</span>
                                        <span className="mt-2 flex flex-wrap items-baseline gap-x-2">
                                            <span className="font-display text-[1.5rem] font-extrabold text-ink">{kobo(amount)}</span>
                                            <span className="text-[0.75rem] whitespace-nowrap text-muted">{t.turnaround}</span>
                                        </span>
                                        <ul className="mt-3 flex flex-col gap-1.5 text-table text-ink">
                                            {t.includes !== undefined && <li className="font-bold text-gold-dark">✓ {t.includes}</li>}
                                            {t.points.map((p) => (
                                                <li key={p} className="flex gap-2">
                                                    <span className="text-gold" aria-hidden="true">✓</span>
                                                    {p}
                                                </li>
                                            ))}
                                        </ul>
                                    </button>
                                );
                            })}
                        </div>

                        {tier === 3 && (
                            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-card bg-sunken px-4 py-3.5">
                                <div>
                                    <p className="text-ui font-extrabold text-ink">Monitoring period</p>
                                    <p className="text-table text-muted">An agent logs opening hours, staff, stock and customer activity on each visit.</p>
                                </div>
                                <div className="flex gap-2" role="radiogroup" aria-label="Monitoring period">
                                    {([7, 14, 30] as const).map((d) => (
                                        <button
                                            key={d}
                                            type="button"
                                            role="radio"
                                            aria-checked={form.data.days === d}
                                            onClick={() => { form.setData('days', d); }}
                                            className={cx('min-h-touch rounded-sm border px-3.5 text-table font-extrabold', form.data.days === d ? 'border-2 border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong bg-raised text-ink')}
                                        >
                                            {d} days
                                            <span className="block text-[0.6875rem] font-semibold text-muted">{kobo(prices.tier3[String(d)] ?? 0)}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>

                    <section className="flex flex-col gap-4 rounded-card bg-ink px-6 py-5 text-inverse sm:flex-row sm:items-center">
                        <div>
                            <p className="text-body font-extrabold">Custom enumeration · organisations only</p>
                            <p className="mt-1 text-table text-inverse/75">Verify hundreds of businesses or collect your own data fields with our field teams, and track it live in your own portal. Opening soon.</p>
                        </div>
                    </section>
                </div>

                <aside className="rounded-card border border-rule bg-raised px-5 py-5 lg:sticky lg:top-6">
                    <h2 className="text-body font-extrabold text-ink">Summary</h2>

                    <div className="mt-3 rounded-sm bg-sunken px-4 py-3">
                        {picked === null ? (
                            <p className="text-ui text-muted">Pick the business from the register results.</p>
                        ) : (
                            <>
                                <p className="text-ui font-extrabold text-ink">{picked.name}</p>
                                <p className="font-mono text-table text-muted">{registration(picked.rcNumber, picked.companyType)}</p>
                            </>
                        )}
                    </div>

                    <dl className="mt-4 flex flex-col gap-2 text-ui">
                        <div className="flex justify-between gap-3"><dt className="text-muted">Tier</dt><dd className="font-bold text-ink">Tier {tier}{tier === 3 ? ` · ${String(form.data.days)} days` : ''}</dd></div>
                        <div className="flex justify-between gap-3"><dt className="text-muted">Turnaround</dt><dd className="font-bold text-ink">{tier === 3 ? `${String(form.data.days)} days of monitoring` : TIERS[tier - 1]?.turnaround}</dd></div>
                    </dl>

                    <p className="mt-4 border-t border-rule pt-4 text-[0.6875rem] font-extrabold tracking-[0.06em] text-muted uppercase">You will get</p>
                    <ul className="mt-2 flex flex-col gap-1 text-table text-ink">
                        {GETS[tier].map((g) => <li key={g}>• {g}</li>)}
                    </ul>

                    <dl className="mt-4 flex flex-col gap-2 border-t border-rule pt-4 text-ui">
                        <div className="flex justify-between"><dt className="text-muted">Wallet balance</dt><dd className="text-ink">{kobo(frame.walletMinor, 2)}</dd></div>
                        <div className="flex items-baseline justify-between"><dt className="text-body font-extrabold text-ink">Total</dt><dd className="font-display text-[1.5rem] font-extrabold text-ink">{kobo(price, 2)}</dd></div>
                        <div className="flex justify-between"><dt className="text-muted">Balance after</dt><dd className={cx(after < 0 ? 'font-bold text-alert-ink' : 'text-muted')}>{kobo(after, 2)}</dd></div>
                    </dl>

                    {form.errors.tier !== undefined && <p role="alert" className="mt-3 text-ui font-semibold text-alert-ink">{form.errors.tier}</p>}

                    <div className="mt-4">
                        {after < 0 ? (
                            <Link href="/enumerate/wallet#fund" className="flex min-h-touch-xl w-full items-center justify-center rounded-sm bg-gold px-6 text-body font-extrabold text-on-accent hover:bg-gold-dark">
                                Fund wallet to continue
                            </Link>
                        ) : (
                            <Button variant="primary" size="field-primary" fullWidth disabled={!canPay} busy={form.processing} onClick={pay}>
                                Pay {kobo(price, 2)} from wallet
                            </Button>
                        )}
                    </div>
                    <p className="mt-3 text-center text-table text-muted">You can follow every step on the verification’s own page.</p>
                </aside>
            </div>
        </EnumerateShell>
    );
}
