import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { PortalShell } from '@/components/PortalShell';
import { DeliveryCard, InspectionReport, ItemsTable, MoneyCard, PurchasePill, Timeline, type PurchaseView } from '@/components/PurchaseParts';

/**
 * The merchant's order, to the mockup's order board: what to pack, where it
 * goes, what they will receive, and the timeline. "Mark as dispatched" is the
 * one action; the money moves when the buyer says so, not the merchant.
 */
export default function Sale({ order, can }: { order: PurchaseView; can: { dispatch: boolean } }) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;
    const [busy, setBusy] = useState(false);

    const dispatch = () => {
        setBusy(true);
        router.post(`/portal/sales/${String(order.id)}/dispatch`, {}, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
            },
        });
    };

    return (
        <PortalShell
            accountName={page.props.auth.portal?.name ?? ''}
            width="page"
            kicker="Orders"
            title={`Order ${order.reference}`}
            subtitle={
                <span className="inline-flex flex-wrap items-center gap-3">
                    <PurchasePill order={order} size="sm" />
                    <span>For {order.buyerName}</span>
                </span>
            }
            actions={
                can.dispatch ? (
                    <Button variant="primary" size="field" busy={busy} onClick={dispatch}>
                        Mark as dispatched
                    </Button>
                ) : undefined
            }
        >
            <Head title={`Order ${order.reference}`} />
            <p className="mb-4 text-ui">
                <Link href="/portal/orders" className="font-bold text-gold hover:text-gold-dark">
                    ← All orders
                </Link>
            </p>
            {errors.order !== undefined && (
                <p role="alert" className="mb-6 rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">
                    {errors.order}
                </p>
            )}
            {order.status === 'disputed' && order.dispute !== null && (
                <section className="mb-6 rounded-card border border-alert/30 bg-alert-soft px-6 py-5">
                    <h2 className="font-display text-display-s text-ink">The buyer raised an issue</h2>
                    <p className="mt-1 text-ui text-ink">“{order.dispute}”</p>
                    <p className="mt-2 text-table text-muted">
                        The money stays held while our team reviews it. We will contact you both.
                    </p>
                </section>
            )}
            {order.status === 'held' && (
                <p className="mb-6 max-w-none rounded-sm bg-held-soft px-4 py-3 text-ui font-semibold text-ink">
                    {order.inspection === null
                        ? 'Paid and held. Pack it and send it, then mark it dispatched so the buyer knows.'
                        : order.inspection.status === 'approved'
                          ? 'The buyer approved the report. Send it, then mark it dispatched.'
                          : order.inspection.status === 'submitted'
                            ? 'Waiting for the buyer to approve the report. You can dispatch once it is approved.'
                            : `Paid and held. A GeoVerify agent will ${order.inspection.kind === 'site_visit' ? 'visit' : 'check the goods'} before it is sent.`}
                </p>
            )}
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div className="flex min-w-0 flex-col gap-6">
                    {order.inspection !== null && <InspectionReport inspection={order.inspection} />}
                    <ItemsTable order={order} />
                    <DeliveryCard order={order} />
                </div>
                <aside className="flex flex-col gap-5">
                    <MoneyCard order={order} side="merchant" />
                    <Timeline order={order} />
                </aside>
            </div>
        </PortalShell>
    );
}
