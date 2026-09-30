import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { EnumerateShell } from '@/components/EnumerateShell';
import { cx } from '@/lib/cx';
import { day, kobo, type EnumerateFrame, type Prices } from '@/lib/enumerate';

interface Props {
    frame: EnumerateFrame;
    prices: Prices;
    month: { addedMinor: number; spentMinor: number; byTier: Record<string, number> };
    statement: { kind: 'in' | 'out'; description: string; reference: string; at: string; amountMinor: number; tier: number | null }[];
    pending: { reference: string; amountMinor: number; channel: string; at: string | null }[];
    minimumMinor: number;
}

type Filter = 'all' | 'in' | 'out';

/**
 * The wallet, to board 31: the balance and what it buys, topping up, this
 * month, and every movement. All of it is read from the ledger; a top-up shows
 * once the payment provider has confirmed it, never before.
 */
export default function Wallet({ frame, prices, month, statement, pending, minimumMinor }: Props) {
    const [filter, setFilter] = useState<Filter>('all');
    const fund = useForm({ amount: '', channel: 'card' as 'card' | 'bank_transfer' });

    const tier2s = prices.tier2 > 0 ? Math.floor(frame.walletMinor / prices.tier2) : 0;
    const tier3s = (prices.tier3['30'] ?? 0) > 0 ? Math.floor(frame.walletMinor / (prices.tier3['30'] ?? 1)) : 0;
    const spent = Math.max(1, month.spentMinor);
    const shown = statement.filter((s) => filter === 'all' || s.kind === filter);

    return (
        <EnumerateShell current="wallet" frame={frame} title="Wallet">
            <Head title="Wallet" />

            <div className="grid gap-5 lg:grid-cols-[1.15fr_1fr_1fr]">
                <section className="relative overflow-hidden rounded-card bg-ink px-6 py-6 text-inverse">
                    <p className="text-[0.6875rem] font-extrabold tracking-[0.06em] text-inverse/60 uppercase">Available balance</p>
                    <p className="mt-2 font-display text-[2.25rem] leading-none font-extrabold">{kobo(frame.walletMinor, 2)}</p>
                    <p className="mt-2 text-table text-inverse/70">
                        Enough for {tier2s} × Tier 2 or {tier3s} × Tier 3 (30 days) verifications
                    </p>
                    <a href="#fund" className="mt-5 inline-flex h-11 items-center rounded-sm bg-logo px-5 text-ui font-extrabold text-ink hover:brightness-95">
                        + Fund wallet
                    </a>
                </section>

                <section id="fund" className="rounded-card border border-rule bg-raised px-5 py-5">
                    <h2 className="text-body font-extrabold text-ink">Fund your wallet</h2>
                    <p className="mt-1 text-table text-muted">By card or bank transfer through Paystack. It shows here once Paystack confirms it, usually within a minute.</p>
                    <form
                        className="mt-4 flex flex-col gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            fund.post('/enumerate/wallet/fund');
                        }}
                    >
                        <label className="flex flex-col gap-1.5">
                            <span className="text-table font-bold text-ink">Amount (₦)</span>
                            <input
                                inputMode="decimal"
                                value={fund.data.amount}
                                onChange={(e) => { fund.setData('amount', e.target.value.replace(/[^\d.]/g, '')); }}
                                placeholder={String(minimumMinor / 100 * 10)}
                                className="h-11 rounded-sm border border-rule-strong bg-raised px-3.5 text-ui text-ink focus:border-gold focus:outline-none"
                                aria-invalid={fund.errors.amount !== undefined || undefined}
                            />
                        </label>
                        <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Pay with">
                            {(
                                [
                                    ['card', 'Card'],
                                    ['bank_transfer', 'Bank transfer'],
                                ] as const
                            ).map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="radio"
                                    aria-checked={fund.data.channel === key}
                                    onClick={() => { fund.setData('channel', key); }}
                                    className={cx('min-h-touch rounded-sm border text-table font-bold', fund.data.channel === key ? 'border-2 border-gold bg-gold-soft text-gold-dark' : 'border-rule-strong text-ink')}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        {fund.errors.amount !== undefined && <p role="alert" className="text-table font-semibold text-alert-ink">{fund.errors.amount}</p>}
                        <Button type="submit" variant="primary" size="field" fullWidth busy={fund.processing} disabled={fund.data.amount === ''}>
                            Continue to payment
                        </Button>
                        <p className="text-[0.75rem] text-muted">Smallest top-up {kobo(minimumMinor)}. Credit is spent on checks; unspent credit is refunded on request.</p>
                    </form>
                </section>

                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <h2 className="text-body font-extrabold text-ink">This month</h2>
                    <dl className="mt-3 flex flex-col gap-2 text-ui">
                        <div className="flex justify-between"><dt className="text-muted">Added</dt><dd className="font-extrabold text-gold-dark">+ {kobo(month.addedMinor, 2)}</dd></div>
                        <div className="flex justify-between"><dt className="text-muted">Spent on verifications</dt><dd className="font-extrabold text-ink">− {kobo(month.spentMinor, 2)}</dd></div>
                    </dl>
                    <div className="mt-3 flex h-2.5 overflow-hidden rounded-full bg-sunken" aria-hidden="true">
                        <span className="bg-graphite/50" style={{ width: `${String(((month.byTier['1'] ?? 0) / spent) * 100)}%` }} />
                        <span className="bg-gold" style={{ width: `${String(((month.byTier['2'] ?? 0) / spent) * 100)}%` }} />
                        <span className="bg-ink" style={{ width: `${String(((month.byTier['3'] ?? 0) / spent) * 100)}%` }} />
                    </div>
                    <p className="mt-2 flex gap-4 text-[0.75rem] text-muted">
                        <span><span className="mr-1 inline-block size-2 rounded-[2px] bg-graphite/50" />Tier 1</span>
                        <span><span className="mr-1 inline-block size-2 rounded-[2px] bg-gold" />Tier 2</span>
                        <span><span className="mr-1 inline-block size-2 rounded-[2px] bg-ink" />Tier 3</span>
                    </p>
                </section>
            </div>

            {pending.length > 0 && (
                <p className="mt-5 rounded-sm bg-held-soft px-4 py-3 text-ui font-semibold text-held-ink">
                    Waiting for Paystack to confirm: {pending.map((p) => `${kobo(p.amountMinor)} (${p.reference})`).join(', ')}. It appears below once confirmed.
                </p>
            )}

            <section className="mt-5 overflow-hidden rounded-card border border-rule bg-raised">
                <div className="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-4">
                    <h2 className="text-body font-extrabold text-ink">Transactions</h2>
                    <div className="flex gap-2">
                        {(
                            [
                                ['all', 'All'],
                                ['in', 'Money in'],
                                ['out', 'Verifications'],
                            ] as const
                        ).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                aria-pressed={filter === key}
                                onClick={() => { setFilter(key); }}
                                className={cx('min-h-[38px] rounded-full border px-4 text-table font-bold', filter === key ? 'border-ink bg-ink text-inverse' : 'border-rule-strong text-ink hover:bg-sunken')}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
                {shown.length === 0 ? (
                    <p className="px-6 py-10 text-center text-ui text-muted">No movements yet.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-left">
                            <thead>
                                <tr className="border-y border-rule bg-sunken/60 text-[0.6875rem] font-extrabold tracking-[0.06em] text-muted uppercase">
                                    <th className="px-6 py-3">Description</th>
                                    <th className="px-3 py-3">Reference</th>
                                    <th className="px-3 py-3">Date</th>
                                    <th className="px-6 py-3 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((s, i) => (
                                    <tr key={`${s.reference}-${String(i)}`} className="border-b border-rule last:border-b-0">
                                        <td className="px-6 py-3.5">
                                            <span className="flex items-center gap-3">
                                                <span className={cx('flex size-7 items-center justify-center rounded-[8px] text-ui font-extrabold', s.kind === 'in' ? 'bg-gold-soft text-gold-dark' : 'bg-sunken text-ink')} aria-hidden="true">
                                                    {s.kind === 'in' ? '+' : '−'}
                                                </span>
                                                <span className="text-ui font-bold text-ink">{s.description}</span>
                                            </span>
                                        </td>
                                        <td className="px-3 py-3.5 font-mono text-table text-muted">{s.reference}</td>
                                        <td className="px-3 py-3.5 text-ui text-muted">{day(s.at)}</td>
                                        <td className={cx('px-6 py-3.5 text-right text-ui font-extrabold', s.kind === 'in' ? 'text-gold-dark' : 'text-ink')}>
                                            {s.kind === 'in' ? '+ ' : '− '}
                                            {kobo(s.amountMinor, 2)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </EnumerateShell>
    );
}
