import { StatusPill } from '@/components/StatusPill';
import { naira, stamp } from '@/lib/money';
import { purchaseTone, type PurchaseStatus } from '@/lib/status';

/** PresentPurchase.php, as the page receives it. */
export interface PurchaseView {
    id: number;
    reference: string;
    status: PurchaseStatus;
    statusLabel: string;
    business: { id: number; name: string | null; place: string | null };
    buyerName: string;
    items: { name: string; unit: string | null; quantity: number; unitNaira: number; lineNaira: number }[];
    protection: 'none' | 'inspection' | 'site_visit';
    protectionLabel: string;
    channel: string;
    money: {
        itemsNaira: number;
        deliveryNaira: number;
        serviceFeeNaira: number;
        totalNaira: number;
        commissionNaira: number;
        commissionRate: number;
        netNaira: number;
    };
    delivery: { name: string; phone: string; address: string; note: string | null };
    dispute: string | null;
    placedAt: string | null;
    timeline: { label: string; at: string | null; detail: string | null }[];
    releaseAfterDays: number;
}

export function PurchasePill({ order, size = 'md' }: { order: Pick<PurchaseView, 'status' | 'statusLabel'>; size?: 'sm' | 'md' }) {
    return <StatusPill size={size} tone={purchaseTone(order.status)} label={order.statusLabel} />;
}

export function ItemsTable({ order }: { order: PurchaseView }) {
    return (
        <section className="rounded-card border border-rule bg-raised" aria-labelledby="items">
            <h2 id="items" className="px-6 pt-5 font-display text-display-s text-ink">
                Items
            </h2>
            <table className="mt-3 w-full border-collapse text-ui">
                <thead>
                    <tr className="border-b border-rule text-left text-table text-muted">
                        <th className="px-6 py-3 font-bold">Product</th>
                        <th className="px-3 py-3 text-right font-bold">Qty</th>
                        <th className="px-6 py-3 text-right font-bold">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {order.items.map((item) => (
                        <tr key={item.name} className="border-b border-rule last:border-b-0">
                            <td className="px-6 py-3 text-ink">
                                <span className="font-bold">{item.name}</span>
                                {item.unit !== null && <span className="text-muted"> · {item.unit}</span>}
                            </td>
                            <td className="px-3 py-3 text-right text-ink">{item.quantity}</td>
                            <td className="px-6 py-3 text-right font-extrabold text-ink">{naira(item.lineNaira)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}

/** The mockup's timeline: a dated step has happened, an undated one has not. */
export function Timeline({ order }: { order: PurchaseView }) {
    return (
        <section className="rounded-card border border-rule bg-raised px-6 py-5" aria-labelledby="timeline">
            <h2 id="timeline" className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">
                Timeline
            </h2>
            <ol className="mt-4 flex list-none flex-col gap-4 p-0">
                {order.timeline.map((step) => {
                    const done = step.at !== null;

                    return (
                        <li key={step.label} className="flex gap-3">
                            <span
                                aria-hidden="true"
                                className={`mt-1 size-3 shrink-0 rounded-full ${done ? 'bg-green' : 'border-2 border-rule-strong'}`}
                            />
                            <span>
                                <span className={`block text-ui font-bold ${done ? 'text-ink' : 'text-faint'}`}>{step.label}</span>
                                <span className="block text-table text-muted">
                                    {done ? [stamp(step.at), step.detail].filter((x) => x !== null && x !== '').join(' · ') : 'Not yet'}
                                </span>
                            </span>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}

export function DeliveryCard({ order }: { order: PurchaseView }) {
    return (
        <section className="rounded-card border border-rule bg-raised px-6 py-5" aria-labelledby="delivery">
            <h2 id="delivery" className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">
                Delivery
            </h2>
            <p className="mt-3 text-ui font-bold text-ink">{order.delivery.name}</p>
            <p className="text-ui text-ink">{order.delivery.address}</p>
            <p className="text-ui text-muted">{order.delivery.phone}</p>
            {order.delivery.note !== null && <p className="mt-2 text-table text-muted">“{order.delivery.note}”</p>}
        </section>
    );
}

/**
 * The money card. The merchant sees what they will receive; the buyer sees
 * what they paid. Both see the same held amount at the top.
 */
export function MoneyCard({ order, side }: { order: PurchaseView; side: 'merchant' | 'buyer' }) {
    const held = ['held', 'dispatched', 'disputed'].includes(order.status);
    const m = order.money;

    return (
        <section className="rounded-card border border-rule bg-raised px-6 py-5 shadow-card" aria-labelledby="money">
            <p id="money" className="text-label font-extrabold tracking-[0.05em] text-held uppercase">
                {held ? 'Held until delivery' : order.statusLabel}
            </p>
            <p className="mt-1 font-display text-display-l text-ink">{naira(m.totalNaira)}</p>
            <dl className="mt-4 flex flex-col gap-2.5 border-t border-rule pt-4 text-ui">
                {side === 'merchant' ? (
                    <>
                        <Row term="Order value" value={naira(m.itemsNaira + m.deliveryNaira)} />
                        <Row term={`Platform commission (${String(m.commissionRate)}%)`} value={`- ${naira(m.commissionNaira)}`} />
                        {m.serviceFeeNaira > 0 && <Row term={order.protectionLabel} value="Paid by buyer" />}
                        <Row term="You receive" value={naira(m.netNaira)} strong />
                    </>
                ) : (
                    <>
                        <Row term="Items" value={naira(m.itemsNaira)} />
                        <Row term="Delivery" value={naira(m.deliveryNaira)} />
                        {m.serviceFeeNaira > 0 && <Row term={order.protectionLabel} value={naira(m.serviceFeeNaira)} />}
                        <Row term={`Paid by ${order.channel}`} value={naira(m.totalNaira)} strong />
                    </>
                )}
            </dl>
        </section>
    );
}

function Row({ term, value, strong = false }: { term: string; value: string; strong?: boolean }) {
    return (
        <div className={`flex justify-between gap-3 ${strong ? 'border-t border-rule pt-2.5' : ''}`}>
            <dt className={strong ? 'font-extrabold text-ink' : 'text-muted'}>{term}</dt>
            <dd className={strong ? 'font-extrabold text-ink' : 'font-bold text-ink'}>{value}</dd>
        </div>
    );
}
