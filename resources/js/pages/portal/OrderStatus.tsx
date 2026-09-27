import { Head, Link, useForm } from "@inertiajs/react";
import { Button } from "@/components/Button";
import { PortalShell } from "@/components/PortalShell";
import { StatusPill } from "@/components/StatusPill";
import { orderTone, type OrderStatus as OrderStatusValue } from "@/lib/status";

interface Props {
    order: {
        id: number;
        reference: string;
        tier: string;
        urgency: string;
        feeNaira: number;
        status: OrderStatusValue;
        statusLabel: string;
        outcome: string | null;
        paidAt: string | null;
        dueBy: string | null;
        completedAt: string | null;
        cancellationReason: string | null;
        businessName: string | null;
        businessId: number;
        within: string;
    };
    payable: boolean;
    certificate: boolean;
    errors: Record<string, string>;
}

function naira(amount: number): string {
    return `₦${amount.toLocaleString("en-NG")}`;
}

function on(date: string | null): string {
    if (date === null) {
        return "not yet";
    }

    return new Date(date).toLocaleDateString("en-NG", {
        day: "numeric",
        month: "long",
        year: "numeric",
    });
}

/**
 * One order, and what is true about it right now.
 *
 * Written to be readable by somebody who is worried. The reference is the first
 * thing on the page because it is what they will quote on the phone, and the
 * promised date is stated as a date rather than as a countdown: a customer
 * chasing us needs to know whether we are late, not how many hours are left.
 */
export default function OrderStatus({
    order,
    payable,
    certificate,
    errors,
}: Props) {
    const form = useForm({});

    return (
        <PortalShell
            accountName={order.businessName}
            kicker={order.businessName ?? "Verification"}
            title={order.tier}
            width="form"
        >
            <Head title={order.reference} />

            {errors.payment !== undefined && (
                <p className="rounded-sm bg-alert-soft mb-6 px-4 py-2.5 text-ui text-alert-ink">
                    {errors.payment}
                </p>
            )}

            <div className="flex flex-col gap-6">
                <div className="flex items-center justify-between gap-4">
                    <p className="numeric-mono text-mono text-muted">
                        {order.reference}
                    </p>
                    <StatusPill
                        label={order.statusLabel}
                        tone={orderTone(order.status)}
                    />
                </div>

                <dl className="flex flex-col rounded-sm bg-sunken px-4 py-1">
                    {[
                        ["Fee", naira(order.feeNaira)],
                        ["Paid", on(order.paidAt)],
                        [
                            "We attend by",
                            order.dueBy === null
                                ? `${order.within} from payment`
                                : on(order.dueBy),
                        ],
                        ...(order.outcome === null
                            ? []
                            : [["Found", order.outcome]]),
                        ...(order.completedAt === null
                            ? []
                            : [["Completed", on(order.completedAt)]]),
                    ].map(([label, value]) => (
                        <div
                            key={label}
                            className="flex items-baseline justify-between gap-4 border-b border-rule py-2.5 last:border-b-0"
                        >
                            <dt className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                {label}
                            </dt>
                            <dd className="numeric-mono text-mono text-ink">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>

                {order.cancellationReason !== null && (
                    <div className="rounded-sm bg-gold-soft flex flex-col gap-2 px-4 py-3">
                        <h2 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                            Refunded
                        </h2>
                        <p className="text-body text-ink">
                            {order.cancellationReason}
                        </p>
                        <p className="text-ui text-muted">
                            The full {naira(order.feeNaira)} goes back to the
                            account you paid from. Your bank usually takes a few
                            working days to show it.
                        </p>
                    </div>
                )}

                {payable && (
                    <form
                        className="flex flex-col gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`/portal/orders/${String(order.id)}/pay`);
                        }}
                    >
                        <Button
                            type="submit"
                            variant="primary"
                            size="field"
                            fullWidth
                            busy={form.processing}
                        >
                            Pay {naira(order.feeNaira)}
                        </Button>
                        <p className="text-ui text-muted">
                            Card, bank transfer or USSD. Nothing is scheduled
                            until the payment clears, and the {order.within} we
                            promise start from then.
                        </p>
                    </form>
                )}

                {certificate && (
                    <div className="rounded-sm bg-gold-soft flex flex-col gap-3 px-4 py-3">
                        <h2 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                            Your certificate
                        </h2>
                        <p className="text-ui text-muted">
                            One page, with what the officer found, where they
                            stood and how accurately, and a code anybody you show
                            it to can check for themselves.
                        </p>
                        <a
                            href={`/portal/orders/${String(order.id)}/certificate.pdf`}
                            className="self-start border border-ink px-4 py-2.5 text-ui text-ink hover:bg-sunken"
                        >
                            Download the certificate
                        </a>
                        <p className="text-label text-faint">
                            Printed fresh each time rather than stored, so it
                            always says what this register says today.
                        </p>
                    </div>
                )}

                <Link
                    href={`/portal/businesses/${String(order.businessId)}`}
                    className="text-ui text-muted underline underline-offset-4 hover:text-ink"
                >
                    Back to the listing
                </Link>
            </div>
        </PortalShell>
    );
}
