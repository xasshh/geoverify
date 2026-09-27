import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { SelectField, TextField } from '@/components/Field';
import { PortalShell } from '@/components/PortalShell';
import { naira, stamp } from '@/lib/money';
import { StatusPill } from '@/components/StatusPill';
import type { StatusTone } from '@/lib/status';

interface Props {
    heldNaira: number;
    availableNaira: number;
    inTransitNaira: number;
    awaitingRelease: number;
    minimumNaira: number;
    account: { bank: string; last4: string; name: string } | null;
    canWithdraw: boolean;
    banks: { code: string; name: string }[];
    payouts: {
        reference: string;
        amountNaira: number;
        status: 'requested' | 'paid' | 'returned';
        to: string | null;
        reason: string | null;
        requestedAt: string | null;
        settledAt: string | null;
    }[];
}

const PAYOUT: Record<Props['payouts'][number]['status'], { tone: StatusTone; label: string }> = {
    requested: { tone: 'progress', label: 'On its way' },
    paid: { tone: 'accepted', label: 'Paid' },
    returned: { tone: 'rejected', label: 'Returned' },
};

/**
 * The wallet, to the mockup's header: held on the left, available on the
 * right, and Withdraw beside it. Both numbers are sums over the ledger, read
 * fresh on every visit.
 */
export default function Wallet(props: Props) {
    const page = usePage();
    const withdraw = useForm({ amount: '' });
    const account = useForm({ bank_code: props.banks[0]?.code ?? '', account_number: '' });

    return (
        <PortalShell accountName={page.props.auth.portal?.name ?? ''} width="page" title="Wallet" subtitle="Money held for orders in progress, and money that is yours to withdraw.">
            <Head title="Wallet" />

            <div className="grid gap-5 md:grid-cols-2">
                <section className="rounded-card border border-rule bg-raised px-6 py-6">
                    <p className="text-label font-extrabold tracking-[0.05em] text-held uppercase">Held until delivery</p>
                    <p className="mt-2 font-display text-display-l text-ink">{naira(props.heldNaira)}</p>
                    <p className="mt-2 text-ui text-muted">
                        {props.awaitingRelease === 0
                            ? 'Nothing is waiting on a buyer.'
                            : `${String(props.awaitingRelease)} ${props.awaitingRelease === 1 ? 'order' : 'orders'} paid and waiting for the buyer to confirm receipt.`}{' '}
                        <Link href="/portal/orders" className="font-bold text-gold hover:text-gold-dark">
                            View orders
                        </Link>
                    </p>
                </section>

                <section className="rounded-card border border-rule bg-raised px-6 py-6 shadow-card">
                    <p className="text-label font-extrabold tracking-[0.05em] text-green uppercase">Available</p>
                    <p className="mt-2 font-display text-display-l text-ink">{naira(props.availableNaira)}</p>
                    {props.inTransitNaira > 0 && <p className="mt-1 text-ui text-muted">{naira(props.inTransitNaira)} on its way to your bank.</p>}

                    {!props.canWithdraw ? (
                        <p className="mt-4 text-ui text-muted">Only an owner of the business can withdraw.</p>
                    ) : props.account === null ? (
                        <p className="mt-4 text-ui text-muted">Add the bank account to pay into, below, to withdraw.</p>
                    ) : (
                        <form
                            className="mt-4 flex flex-wrap items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                withdraw.post('/portal/wallet/withdraw', { preserveScroll: true, onSuccess: () => { withdraw.reset(); } });
                            }}
                        >
                            <div className="w-44">
                                <TextField
                                    label="Amount (₦)"
                                    inputMode="numeric"
                                    value={withdraw.data.amount}
                                    onChange={(e) => { withdraw.setData('amount', e.target.value.replace(/\D/g, '')); }}
                                    {...(withdraw.errors.amount === undefined ? {} : { error: withdraw.errors.amount })}
                                />
                            </div>
                            <Button type="submit" variant="primary" size="field-compact" busy={withdraw.processing} disabled={props.availableNaira < props.minimumNaira}>
                                Withdraw
                            </Button>
                            <p className="w-full text-table text-muted">
                                To {props.account.bank} ••••{props.account.last4}. Smallest withdrawal {naira(props.minimumNaira)}.
                            </p>
                        </form>
                    )}
                </section>
            </div>

            {props.canWithdraw && (
                <section className="mt-6 rounded-card border border-rule bg-raised px-6 py-6">
                    <h2 className="font-display text-display-s text-ink">Where we pay you</h2>
                    {props.account !== null && (
                        <p className="mt-1 text-ui text-ink">
                            {props.account.name}, {props.account.bank} ••••{props.account.last4}
                        </p>
                    )}
                    {props.banks.length === 0 ? (
                        <p className="mt-3 text-ui text-muted">The list of banks could not be loaded just now. Try again in a few minutes.</p>
                    ) : (
                        <form
                            className="mt-4 grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                            onSubmit={(e) => {
                                e.preventDefault();
                                account.post('/portal/wallet/account', { preserveScroll: true, onSuccess: () => { account.setData('account_number', ''); } });
                            }}
                        >
                            <SelectField label="Bank" value={account.data.bank_code} onChange={(e) => { account.setData('bank_code', e.target.value); }}>
                                {props.banks.map((b) => (
                                    <option key={b.code} value={b.code}>
                                        {b.name}
                                    </option>
                                ))}
                            </SelectField>
                            <TextField
                                label="Account number"
                                inputMode="numeric"
                                maxLength={10}
                                value={account.data.account_number}
                                onChange={(e) => { account.setData('account_number', e.target.value.replace(/\D/g, '')); }}
                                {...(account.errors.account_number === undefined ? {} : { error: account.errors.account_number })}
                            />
                            <Button type="submit" variant="secondary" size="field-compact" busy={account.processing}>
                                {props.account === null ? 'Add account' : 'Change account'}
                            </Button>
                        </form>
                    )}
                    <p className="mt-3 text-table text-muted">We check the name with your bank before saving, and keep only the last four digits.</p>
                </section>
            )}

            <section className="mt-6">
                <h2 className="font-display text-display-s text-ink">Withdrawals</h2>
                {props.payouts.length === 0 ? (
                    <p className="mt-3 rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted">None yet.</p>
                ) : (
                    <ul className="mt-3 flex list-none flex-col gap-2 p-0">
                        {props.payouts.map((p) => (
                            <li key={p.reference} className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-rule bg-raised px-5 py-4">
                                <span>
                                    <span className="block font-extrabold text-ink">{naira(p.amountNaira)}</span>
                                    <span className="block text-table text-muted">
                                        {p.to} · {stamp(p.requestedAt)}
                                        {p.reason !== null && ` · ${p.reason}`}
                                    </span>
                                </span>
                                <StatusPill size="sm" tone={PAYOUT[p.status].tone} label={PAYOUT[p.status].label} />
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </PortalShell>
    );
}
