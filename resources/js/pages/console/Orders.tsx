import { useState } from "react";
import { Head, useForm, usePage } from "@inertiajs/react";
import { Button } from "@/components/Button";
import { ConsoleShell } from "@/components/ConsoleShell";
import { StatusPill } from "@/components/StatusPill";
import { orderTone, type OrderStatus } from "@/lib/status";

interface Order {
    id: number;
    reference: string;
    business: string;
    party: string | null;
    ward: string | null;
    tier: string;
    urgency: string;
    zone: string;
    feeNaira: number;
    status: OrderStatus;
    statusLabel: string;
    paidAt: string | null;
    dueBy: string | null;
    daysLeft: number | null;
    assignmentId: number | null;
    awaitingAcceptance: boolean;
    assignable: boolean;
}

interface Officer {
    id: number;
    name: string;
    staffRef: string | null;
    visits: number;
}

interface Outcome {
    value: string;
    label: string;
    establishesTier: boolean;
}

interface Props {
    orders: Order[];
    officers: Officer[];
    outcomes: Outcome[];
    held: number;
}

function naira(amount: number): string {
    return `₦${amount.toLocaleString("en-NG")}`;
}

/**
 * How long is left, said as a supervisor thinks about it.
 *
 * Late is stated as late, in days, and never softened into "due". The number
 * this screen exists to prevent is the count of orders that quietly went past
 * their date, and a row that reads "due" when it is four days over is how that
 * count grows.
 */
function clock(daysLeft: number | null): { text: string; late: boolean } {
    if (daysLeft === null) {
        return { text: "no date", late: false };
    }

    if (daysLeft < 0) {
        return { text: `${String(Math.abs(daysLeft))} days late`, late: true };
    }

    if (daysLeft === 0) {
        return { text: "due today", late: false };
    }

    return { text: `${String(daysLeft)} days left`, late: false };
}

/** Giving a paid visit to somebody. */
function AssignRow({ order, officers }: { order: Order; officers: Officer[] }) {
    const form = useForm({ officer_id: "" });

    return (
        <form
            className="mt-3 flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/console/orders/${String(order.id)}/assign`, {
                    preserveScroll: true,
                });
            }}
        >
            <label className="flex flex-col gap-1">
                <span className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                    Send
                </span>
                <select
                    value={form.data.officer_id}
                    onChange={(event) => {
                        form.setData("officer_id", event.target.value);
                    }}
                    className="rounded-sm border border-rule-strong bg-raised px-3 py-2.5 text-ui text-ink"
                >
                    <option value="">Choose an officer</option>
                    {officers.map((officer) => (
                        <option key={officer.id} value={String(officer.id)}>
                            {officer.name}
                            {officer.visits > 0
                                ? ` (${String(officer.visits)} visits)`
                                : ""}
                        </option>
                    ))}
                </select>
            </label>

            <Button
                type="submit"
                busy={form.processing}
                disabled={form.data.officer_id === ""}
            >
                Assign visit
            </Button>

            {Object.values(form.errors).map((error) => (
                <p key={error} className="text-label text-alert">
                    {error}
                </p>
            ))}
        </form>
    );
}

/**
 * Accepting the work, which is the moment the fee stops being the customer's.
 *
 * The outcome is chosen explicitly and the screen says which outcomes establish
 * the tier. A supervisor clicking through this without reading it would be
 * publishing a verification, and the wording is there to slow that down by
 * exactly one beat.
 */
function AcceptRow({ order, outcomes }: { order: Order; outcomes: Outcome[] }) {
    const form = useForm({ outcome: "confirmed", note: "" });

    return (
        <form
            className="mt-3 flex flex-col gap-3 rounded-card border border-rule bg-raised p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/console/orders/${String(order.id)}/complete`, {
                    preserveScroll: true,
                });
            }}
        >
            <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                What the officer found
            </p>

            <div className="flex flex-wrap gap-2">
                {outcomes.map((outcome) => (
                    <button
                        key={outcome.value}
                        type="button"
                        aria-pressed={form.data.outcome === outcome.value}
                        onClick={() => {
                            form.setData("outcome", outcome.value);
                        }}
                        className={
                            form.data.outcome === outcome.value
                                ? "rounded-sm border border-ink bg-ink px-3 py-1.5 text-ui text-surface"
                                : "rounded-sm border border-rule-strong px-3 py-1.5 text-ui text-muted hover:border-ink hover:text-ink"
                        }
                    >
                        {outcome.label}
                    </button>
                ))}
            </div>

            <p className="text-label text-faint">
                {outcomes.find((o) => o.value === form.data.outcome)
                    ?.establishesTier === true
                    ? "This establishes the tier on the listing, dated today."
                    : "This does not establish the tier. The visit is still billable and the report is still delivered."}
            </p>

            <textarea
                rows={2}
                placeholder="Anything the record should carry (optional)"
                value={form.data.note}
                onChange={(event) => {
                    form.setData("note", event.target.value);
                }}
                className="w-full rounded-sm border border-rule-strong bg-raised px-3 py-2.5 text-ui text-ink"
            />

            {Object.values(form.errors).map((error) => (
                <p key={error} className="text-label text-alert">
                    {error}
                </p>
            ))}

            <div>
                <Button type="submit" busy={form.processing}>
                    Accept and recognise {naira(order.feeNaira)}
                </Button>
            </div>
        </form>
    );
}

function Row({
    order,
    officers,
    outcomes,
}: {
    order: Order;
    officers: Officer[];
    outcomes: Outcome[];
}) {
    const [refunding, setRefunding] = useState(false);
    const refund = useForm({ reason: "" });
    const time = clock(order.daysLeft);

    return (
        <li className="rounded-card border border-rule p-4 bg-raised">
            <header className="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h2 className="font-display text-display-s text-ink">
                        {order.business}
                    </h2>
                    <p className="numeric-mono text-label text-faint">
                        {order.reference}
                        {order.ward === null ? "" : ` · ${order.ward}`} · zone{" "}
                        {order.zone}
                        {order.party === null ? "" : ` · ${order.party}`}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <span
                        className={
                            time.late
                                ? "numeric-mono text-mono font-semibold text-alert"
                                : "numeric-mono text-mono text-muted"
                        }
                    >
                        {time.text}
                    </span>
                    <StatusPill
                        label={order.statusLabel}
                        tone={orderTone(order.status)}
                    />
                </div>
            </header>

            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1">
                {[
                    ["Tier", order.tier.replace(/_/g, " ")],
                    ["Urgency", order.urgency],
                    ["Fee", naira(order.feeNaira)],
                    ["Promised", order.dueBy ?? "not yet"],
                ].map(([label, value]) => (
                    <div key={label} className="flex items-baseline gap-2">
                        <dt className="text-label font-semibold tracking-[0.05em] text-faint uppercase">
                            {label}
                        </dt>
                        <dd className="numeric-mono text-mono text-ink">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>

            {order.assignable && (
                <AssignRow order={order} officers={officers} />
            )}
            {order.awaitingAcceptance && (
                <AcceptRow order={order} outcomes={outcomes} />
            )}

            {refunding ? (
                <form
                    className="mt-3 flex flex-col gap-2 rounded-sm border border-alert bg-raised p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        refund.post(
                            `/console/orders/${String(order.id)}/refund`,
                            {
                                preserveScroll: true,
                            },
                        );
                    }}
                >
                    <label className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                        Why the money is going back
                    </label>
                    <p className="text-label text-faint">
                        The customer is shown this sentence on their order.
                    </p>
                    <input
                        value={refund.data.reason}
                        onChange={(event) => {
                            refund.setData("reason", event.target.value);
                        }}
                        className="w-full rounded-sm border border-rule-strong bg-raised px-3 py-2.5 text-ui text-ink"
                    />
                    {Object.values(refund.errors).map((error) => (
                        <p key={error} className="text-label text-alert">
                            {error}
                        </p>
                    ))}
                    <div className="flex gap-3">
                        <Button type="submit" busy={refund.processing}>
                            Refund {naira(order.feeNaira)}
                        </Button>
                        <button
                            type="button"
                            onClick={() => {
                                setRefunding(false);
                            }}
                            className="text-ui text-muted underline underline-offset-2"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <button
                    type="button"
                    onClick={() => {
                        setRefunding(true);
                    }}
                    className="mt-3 text-label text-muted underline underline-offset-2 hover:text-alert"
                >
                    Refund this order
                </button>
            )}
        </li>
    );
}

/**
 * Paid work, oldest promise first.
 *
 * Sorted by the date we committed to rather than by the date somebody paid, so
 * an express order placed this morning sits above a standard one from last
 * week. The customer bought a date, and the queue is ordered by the dates we
 * are closest to missing.
 */
export default function Orders({ orders, officers, outcomes, held }: Props) {
    const flash = usePage().props.flash.status;
    const late = orders.filter(
        (o) => o.daysLeft !== null && o.daysLeft < 0,
    ).length;

    return (
        <ConsoleShell current="orders">
            <Head title="Verifications" />

            <div className="mx-auto max-w-[1000px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b border-rule pb-3">
                    <div>
                        <h1 className="font-display text-display-l text-ink">
                            Verifications
                        </h1>
                        <p className="mt-1 text-ui text-muted">
                            Businesses who have paid for a visit, and what we
                            owe them.
                        </p>
                    </div>
                    <dl className="flex gap-6">
                        <div>
                            <dt className="text-label font-semibold tracking-[0.05em] text-faint uppercase">
                                Held
                            </dt>
                            <dd className="numeric-mono text-mono text-ink">
                                {naira(held)}
                            </dd>
                        </div>
                        {late > 0 && (
                            <div>
                                <dt className="text-label font-semibold tracking-[0.05em] text-faint uppercase">
                                    Late
                                </dt>
                                <dd className="numeric-mono text-mono text-alert">
                                    {late}
                                </dd>
                            </div>
                        )}
                    </dl>
                </header>

                {flash !== null && (
                    <p
                        role="status"
                        className="rounded-sm bg-green-soft mt-6 px-4 py-2.5 text-ui text-ink"
                    >
                        {flash}
                    </p>
                )}

                {orders.length === 0 ? (
                    <p className="mt-10 text-body text-muted">
                        Nothing is waiting. Every paid visit has been given out
                        and accepted.
                    </p>
                ) : (
                    <ul className="mt-6 flex flex-col gap-4">
                        {orders.map((order) => (
                            <Row
                                key={order.id}
                                order={order}
                                officers={officers}
                                outcomes={outcomes}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </ConsoleShell>
    );
}
