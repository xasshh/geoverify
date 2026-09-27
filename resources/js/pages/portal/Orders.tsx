import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PortalShell } from '@/components/PortalShell';
import { StatusPill } from '@/components/StatusPill';
import { cx } from '@/lib/cx';
import { PurchasePill } from '@/components/PurchaseParts';
import { naira } from '@/lib/money';
import { orderTone, type OrderStatus, type PurchaseStatus } from '@/lib/status';

interface Sale {
    id: number;
    reference: string;
    buyer: string;
    items: string;
    totalNaira: number;
    service: string | null;
    status: PurchaseStatus;
    statusLabel: string;
    paidAt: string | null;
}

// The mockup's chips over the product orders.
const SALE_FILTERS: { key: string; label: string; match: (o: Sale) => boolean }[] = [
    { key: 'all', label: 'All', match: () => true },
    { key: 'fulfil', label: 'To fulfil', match: (o) => o.status === 'held' },
    { key: 'held', label: 'Held', match: (o) => ['held', 'dispatched', 'disputed'].includes(o.status) },
    { key: 'released', label: 'Released', match: (o) => o.status === 'released' },
];

interface Order {
    id: number;
    reference: string;
    business: string | null;
    tier: string;
    feeNaira: number;
    status: OrderStatus;
    statusLabel: string;
    orderedAt: string | null;
    byInvestor: boolean;
    open: boolean;
}

const FILTERS: { key: string; label: string; match: (o: Order) => boolean }[] = [
    { key: 'all', label: 'All', match: () => true },
    { key: 'open', label: 'In progress', match: (o) => !['completed', 'cancelled', 'refunded'].includes(o.status) },
    { key: 'completed', label: 'Completed', match: (o) => o.status === 'completed' },
    { key: 'refunded', label: 'Refunded', match: (o) => o.status === 'refunded' || o.status === 'cancelled' },
];

function stamp(iso: string | null): [string, string] {
    if (iso === null) {
        return ['', ''];
    }

    const d = new Date(iso);

    return [
        d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }),
        d.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit' }),
    ];
}

/**
 * Orders: product orders first, the way the mockup lays them out, then the
 * verification visits on your businesses. Each has its own chips.
 */
export default function Orders({ orders, sales }: { orders: Order[]; sales: Sale[] }) {
    const accountName = usePage().props.auth.portal?.name ?? '';
    const [filter, setFilter] = useState('all');
    const [saleFilter, setSaleFilter] = useState('all');
    const saleActive = SALE_FILTERS.find((f) => f.key === saleFilter) ?? SALE_FILTERS[0];
    const shownSales = saleActive === undefined ? sales : sales.filter(saleActive.match);
    const active = FILTERS.find((f) => f.key === filter) ?? FILTERS[0];
    const shown = active === undefined ? orders : orders.filter(active.match);

    return (
        <PortalShell
            accountName={accountName}
            width="page"
            title="Orders"
            subtitle="What buyers have ordered from you, and the verification visits on your businesses."
        >
            <Head title="Orders" />
            <h2 className="mb-3 font-display text-display-s text-ink">Product orders</h2>
            <div className="mb-4 flex flex-wrap gap-2">
                {SALE_FILTERS.map((f) => (
                    <button
                        key={f.key}
                        type="button"
                        onClick={() => {
                            setSaleFilter(f.key);
                        }}
                        aria-pressed={saleFilter === f.key}
                        className={cx(
                            'min-h-touch rounded-sm border px-4 text-ui font-bold',
                            saleFilter === f.key ? 'border-ink bg-ink text-inverse' : 'border-rule-strong bg-raised text-ink hover:bg-sunken',
                        )}
                    >
                        {f.label}
                    </button>
                ))}
            </div>
            {shownSales.length === 0 ? (
                <p className="mb-10 rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                    {sales.length === 0 ? 'No product orders yet. Buyers can order anything on your listings that has a price.' : 'Nothing here.'}
                </p>
            ) : (
                <div className="mb-10 overflow-x-auto rounded-card border border-rule bg-raised">
                    <table className="w-full min-w-[900px] border-collapse text-ui">
                        <thead>
                            <tr className="border-b border-rule text-left text-table text-muted">
                                <th className="px-5 py-4 font-bold">S/N</th>
                                <th className="px-5 py-4 font-bold">Order</th>
                                <th className="px-5 py-4 font-bold">Buyer</th>
                                <th className="px-5 py-4 font-bold">Items</th>
                                <th className="px-5 py-4 font-bold">Amount</th>
                                <th className="px-5 py-4 font-bold">Service</th>
                                <th className="px-5 py-4 font-bold">Payment</th>
                                <th className="px-5 py-4 font-bold">Date</th>
                                <th className="px-5 py-4"><span className="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {shownSales.map((o, i) => {
                                const [day, time] = stamp(o.paidAt);

                                return (
                                    <tr key={o.id} className="border-b border-rule last:border-b-0">
                                        <td className="px-5 py-4 text-muted">{i + 1}</td>
                                        <td className="px-5 py-4 numeric-mono text-mono font-medium text-ink">{o.reference}</td>
                                        <td className="px-5 py-4 font-bold text-ink">{o.buyer}</td>
                                        <td className="max-w-[220px] px-5 py-4 text-ink">{o.items}</td>
                                        <td className="px-5 py-4 font-extrabold text-ink">{naira(o.totalNaira)}</td>
                                        <td className="px-5 py-4 text-ink">{o.service ?? 'None'}</td>
                                        <td className="px-5 py-4">
                                            <PurchasePill order={o} size="sm" />
                                        </td>
                                        <td className="px-5 py-4 text-ink">
                                            {day}
                                            <span className="block text-table text-muted">{time}</span>
                                        </td>
                                        <td className="px-5 py-4 text-right">
                                            <Link href={`/portal/sales/${String(o.id)}`} className="font-extrabold text-gold hover:text-gold-dark">
                                                Open
                                            </Link>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            <h2 className="mb-3 font-display text-display-s text-ink">Verification visits</h2>
            <div className="mb-4 flex flex-wrap gap-2">
                {FILTERS.map((f) => (
                    <button
                        key={f.key}
                        type="button"
                        onClick={() => {
                            setFilter(f.key);
                        }}
                        aria-pressed={filter === f.key}
                        className={cx(
                            'min-h-touch rounded-sm border px-4 text-ui font-bold',
                            filter === f.key ? 'border-ink bg-ink text-inverse' : 'border-rule-strong bg-raised text-ink hover:bg-sunken',
                        )}
                    >
                        {f.label}
                    </button>
                ))}
            </div>
            {shown.length === 0 ? (
                <p className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">Nothing here.</p>
            ) : (
                <div className="overflow-x-auto rounded-card border border-rule bg-raised">
                    <table className="w-full min-w-[820px] border-collapse text-ui">
                        <thead>
                            <tr className="border-b border-rule text-left text-table text-muted">
                                <th className="px-5 py-4 font-bold">S/N</th>
                                <th className="px-5 py-4 font-bold">Order</th>
                                <th className="px-5 py-4 font-bold">Business</th>
                                <th className="px-5 py-4 font-bold">Service</th>
                                <th className="px-5 py-4 font-bold">Amount</th>
                                <th className="px-5 py-4 font-bold">Status</th>
                                <th className="px-5 py-4 font-bold">Date</th>
                                <th className="px-5 py-4"><span className="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((o, i) => {
                                const [day, time] = stamp(o.orderedAt);

                                return (
                                    <tr key={o.id} className="border-b border-rule last:border-b-0">
                                        <td className="px-5 py-4 text-muted">{i + 1}</td>
                                        <td className="px-5 py-4 numeric-mono text-mono font-medium text-ink">{o.reference}</td>
                                        <td className="px-5 py-4 font-bold text-ink">{o.business}</td>
                                        <td className="px-5 py-4 text-ink">
                                            <span className="capitalize">{o.tier}</span>
                                            {o.byInvestor && <span className="block text-table text-muted">Requested by an investor</span>}
                                        </td>
                                        <td className="px-5 py-4 font-extrabold text-ink">₦{o.feeNaira.toLocaleString('en-NG')}</td>
                                        <td className="px-5 py-4">
                                            <StatusPill size="sm" tone={orderTone(o.status)} label={o.statusLabel} />
                                        </td>
                                        <td className="px-5 py-4 text-ink">
                                            {day}
                                            <span className="block text-table text-muted">{time}</span>
                                        </td>
                                        <td className="px-5 py-4 text-right">
                                            {o.open && (
                                                <Link href={`/portal/orders/${String(o.id)}`} className="font-extrabold text-gold hover:text-gold-dark">
                                                    Open
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </PortalShell>
    );
}
