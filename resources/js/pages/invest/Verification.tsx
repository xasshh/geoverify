import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { InvestorShell } from '@/components/InvestorShell';
import { OrderTracker, type TrackerStep } from '@/components/OrderTracker';
import { StatusPill } from '@/components/StatusPill';
import { orderTone, type OrderStatus } from '@/lib/status';

interface Props {
    order: {
        id: number;
        reference: string;
        business: string | null;
        tier: string;
        urgency: string;
        feeNaira: number;
        status: OrderStatus;
        statusLabel: string;
        orderedAt: string | null;
        dueBy: string | null;
        completedAt: string | null;
        certificate: boolean;
        buys: string | null;
        happens: string | null;
        within: string;
        steps: TrackerStep[];
        cancellationReason: string | null;
    };
    payable: boolean;
}

/** One commissioned visit: where it stands, what it cost, and its certificate. */
export default function Verification({ order, payable }: Props) {
    const pay = useForm({});
    const paymentError = usePage().props.errors.payment;

    return (
        <InvestorShell
            current="reports"
            crumbs={
                <Link href="/invest/verifications" className="hover:text-ink">
                    Commissioned verifications
                </Link>
            }
            title={
                <span className="flex flex-wrap items-center gap-3">
                    {order.tier}
                    <span className="numeric-mono text-[1.5rem] font-medium text-muted">{order.reference}</span>
                </span>
            }
            subtitle={order.business ?? undefined}
            actions={<StatusPill tone={orderTone(order.status)} label={order.statusLabel} />}
        >
            <Head title={order.reference} />
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px] xl:items-start">
                <section className="rounded-card border border-rule bg-raised p-6 shadow-card sm:p-7">
                    <h2 className="font-display text-display-s text-ink">Progress</h2>
                    <div className="mt-6">
                        <OrderTracker steps={order.steps} />
                    </div>
                    {order.happens !== null && <p className="mt-6 text-ui text-muted">{order.happens}</p>}
                    {order.cancellationReason !== null && (
                        <p className="mt-4 rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{order.cancellationReason}</p>
                    )}
                </section>

                <section className="rounded-card bg-ink p-7 text-inverse shadow-card">
                    <p className="text-label font-bold tracking-[0.05em] text-inverse/70 uppercase">
                        {payable ? 'To pay' : order.status === 'refunded' ? 'Refunded' : order.status === 'completed' ? 'Paid' : 'Held until the report is accepted'}
                    </p>
                    <p className="mt-2 font-display text-display-xl">₦{order.feeNaira.toLocaleString('en-NG')}</p>
                    <dl className="mt-5 border-t border-inverse/15 pt-2 text-ui">
                        {(
                            [
                                ['Speed', order.urgency === 'express' ? 'Express' : 'Standard'],
                                ['Report within', order.within],
                                ['Due by', order.dueBy ?? 'Set when payment clears'],
                            ] as const
                        ).map(([k, v]) => (
                            <div key={k} className="flex justify-between gap-4 py-2">
                                <dt className="text-inverse/75">{k}</dt>
                                <dd className="font-extrabold">{v}</dd>
                            </div>
                        ))}
                    </dl>
                    {payable && (
                        <Button
                            variant="primary"
                            size="field-primary"
                            fullWidth
                            busy={pay.processing}
                            onClick={() => {
                                pay.post(`/invest/verifications/${String(order.id)}/pay`);
                            }}
                        >
                            Pay by card, transfer or USSD
                        </Button>
                    )}
                    {paymentError !== undefined && (
                        <p className="mt-3 rounded-sm bg-alert-soft px-3 py-2 text-table text-alert-ink">{paymentError}</p>
                    )}
                    {order.certificate && (
                        <a
                            href={`/invest/verifications/${String(order.id)}/certificate.pdf`}
                            className="mt-4 flex min-h-touch-lg items-center justify-center rounded-sm bg-logo text-ui font-extrabold text-ink"
                        >
                            Download certificate (PDF)
                        </a>
                    )}
                </section>
            </div>
        </InvestorShell>
    );
}
