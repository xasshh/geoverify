import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/Button';
import { PortalShell } from '@/components/PortalShell';
import { DeliveryCard, ItemsTable, MoneyCard, PurchasePill, Timeline, type PurchaseView } from '@/components/PurchaseParts';
import { clearCart } from '@/lib/cart';

interface Props {
    order: PurchaseView;
    can: { pay: boolean; cancel: boolean; confirm: boolean; raiseIssue: boolean };
}

/**
 * The buyer's order: where the money is, and the two things only the buyer can
 * say. "I have it" releases the money to the merchant; "something is wrong"
 * keeps it held until a person has looked.
 */
export default function Purchase({ order, can }: Props) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;
    const [asking, setAsking] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState<string | null>(null);

    // The cart this order was placed from has done its job.
    useEffect(() => {
        if (order.status !== 'awaiting_payment' && order.status !== 'cancelled') {
            clearCart(order.business.id);
        }
    }, [order.status, order.business.id]);

    const post = (action: string, data: Record<string, string> = {}) => {
        setBusy(action);
        router.post(`/portal/purchases/${String(order.id)}/${action}`, data, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(null);
            },
        });
    };

    return (
        <PortalShell
            accountName={page.props.auth.portal?.name ?? ''}
            width="page"
            kicker="My orders"
            title={`Order ${order.reference}`}
            subtitle={
                <span className="inline-flex flex-wrap items-center gap-3">
                    <PurchasePill order={order} size="sm" />
                    <Link href={`/directory/${String(order.business.id)}`} className="font-bold text-gold hover:text-gold-dark">
                        {order.business.name}
                    </Link>
                    {order.business.place !== null && <span>{order.business.place}</span>}
                </span>
            }
        >
            <Head title={`Order ${order.reference}`} />
            {(errors.order ?? errors.payment) !== undefined && (
                <p role="alert" className="mb-6 rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">
                    {errors.order ?? errors.payment}
                </p>
            )}

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div className="flex min-w-0 flex-col gap-6">
                    {(can.confirm || can.pay) && (
                        <section className="rounded-card border border-gold/40 bg-gold-soft px-6 py-5">
                            {can.pay ? (
                                <>
                                    <h2 className="font-display text-display-s text-ink">Not paid yet</h2>
                                    <p className="mt-1 text-ui text-ink">Nothing has been charged. Pay to have the business pack your order.</p>
                                    <div className="mt-4 flex flex-wrap gap-3">
                                        <Button variant="primary" size="field" busy={busy === 'pay'} onClick={() => { post('pay'); }}>
                                            Pay and hold
                                        </Button>
                                        {can.cancel && (
                                            <Button variant="quiet" size="field" busy={busy === 'cancel'} onClick={() => { post('cancel'); }}>
                                                Cancel order
                                            </Button>
                                        )}
                                    </div>
                                </>
                            ) : (
                                <>
                                    <h2 className="font-display text-display-s text-ink">
                                        {order.status === 'dispatched' ? 'Your order is on its way' : 'Your money is held'}
                                    </h2>
                                    <p className="mt-1 text-ui text-ink">
                                        The business is paid only when you confirm you have it.
                                        {order.status === 'dispatched' &&
                                            ` If you do neither within ${String(order.releaseAfterDays)} days of dispatch, it is released to them.`}
                                    </p>
                                    <div className="mt-4 flex flex-wrap gap-3">
                                        <Button variant="primary" size="field" busy={busy === 'confirm'} onClick={() => { post('confirm'); }}>
                                            I received my order
                                        </Button>
                                        {can.raiseIssue && (
                                            <Button variant="secondary" size="field" onClick={() => { setAsking((a) => !a); }}>
                                                Something is wrong
                                            </Button>
                                        )}
                                    </div>
                                    {asking && (
                                        <form
                                            className="mt-5 border-t border-gold/30 pt-5"
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                post('issue', { reason });
                                            }}
                                        >
                                            <label htmlFor="reason" className="text-label font-bold tracking-[0.05em] text-muted uppercase">
                                                What went wrong
                                            </label>
                                            <textarea
                                                id="reason"
                                                rows={3}
                                                value={reason}
                                                onChange={(e) => { setReason(e.target.value); }}
                                                className="mt-2 w-full rounded-sm border border-rule-strong bg-raised p-3 text-ui text-ink focus:border-gold focus:outline-2 focus:outline-gold"
                                            />
                                            <p className="mt-2 text-table text-muted">
                                                The money stays held while our team looks at it with you and the business.
                                            </p>
                                            <div className="mt-3">
                                                <Button type="submit" variant="destructive" size="field" busy={busy === 'issue'}>
                                                    Raise the issue
                                                </Button>
                                            </div>
                                        </form>
                                    )}
                                </>
                            )}
                        </section>
                    )}

                    {order.status === 'disputed' && order.dispute !== null && (
                        <section className="rounded-card border border-alert/30 bg-alert-soft px-6 py-5">
                            <h2 className="font-display text-display-s text-ink">You raised an issue</h2>
                            <p className="mt-1 text-ui text-ink">“{order.dispute}”</p>
                            <p className="mt-2 text-table text-muted">Held until our team rules. If it is sorted between you, confirm delivery above.</p>
                        </section>
                    )}

                    <ItemsTable order={order} />
                </div>

                <aside className="flex flex-col gap-5">
                    <MoneyCard order={order} side="buyer" />
                    <Timeline order={order} />
                    <DeliveryCard order={order} />
                </aside>
            </div>
        </PortalShell>
    );
}
