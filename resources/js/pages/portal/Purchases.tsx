import { Head, Link, usePage } from '@inertiajs/react';
import { PortalShell } from '@/components/PortalShell';
import { PurchasePill } from '@/components/PurchaseParts';
import { naira, stamp } from '@/lib/money';
import type { PurchaseStatus } from '@/lib/status';

interface Row {
    id: number;
    reference: string;
    business: string | null;
    items: number;
    totalNaira: number;
    status: PurchaseStatus;
    statusLabel: string;
    placedAt: string | null;
}

/** My orders: what this account has bought, newest first. */
export default function Purchases({ orders }: { orders: Row[] }) {
    return (
        <PortalShell accountName={usePage().props.auth.portal?.name ?? ''} width="page" title="My orders" subtitle="Things you bought on GeoVerify, and where your money is.">
            <Head title="My orders" />
            {orders.length === 0 ? (
                <div className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                    <p>You have not bought anything yet.</p>
                    <Link href="/directory" className="mt-2 inline-block font-bold text-gold hover:text-gold-dark">
                        Explore the directory
                    </Link>
                </div>
            ) : (
                <ul className="flex list-none flex-col gap-3 p-0">
                    {orders.map((o) => (
                        <li key={o.id}>
                            <Link
                                href={`/portal/purchases/${String(o.id)}`}
                                className="flex flex-wrap items-center justify-between gap-4 rounded-card border border-rule bg-raised px-5 py-4 hover:border-rule-strong"
                            >
                                <span>
                                    <span className="block text-body font-extrabold text-ink">{o.business}</span>
                                    <span className="block text-table text-muted">
                                        <span className="numeric-mono">{o.reference}</span> · {o.items} {o.items === 1 ? 'line' : 'lines'} · {stamp(o.placedAt)}
                                    </span>
                                </span>
                                <span className="flex items-center gap-4">
                                    <span className="font-extrabold text-ink">{naira(o.totalNaira)}</span>
                                    <PurchasePill order={o} size="sm" />
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalShell>
    );
}
