import { useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import { ConsoleShell } from "@/components/ConsoleShell";
import { Button } from "@/components/Button";

interface Signal {
    kind: string;
    label: string;
    result: string;
    supportingOnly: boolean;
    detail: string | null;
}

interface QueueClaim {
    id: number;
    assertedAt: string;
    tradingName: string;
    ward: string | null;
    party: { code: string; name: string };
    relationship: string;
    signals: Signal[];
}

interface Dispute {
    id: number;
    openedAt: string;
    tradingName: string;
    challenger: {
        code: string;
        name: string;
        relationship: string;
        signals: Signal[];
    };
    incumbent: { code: string; name: string; heldSince: string; via: string };
}

interface Props {
    disputes: Dispute[];
    claims: QueueClaim[];
}

function on(iso: string): string {
    return new Date(iso).toLocaleDateString("en-NG", {
        day: "numeric",
        month: "short",
        year: "numeric",
    });
}

function waitingDays(iso: string): number {
    return Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
}

/**
 * The claims a person has to decide.
 *
 * Disputes sit above ordinary claims because a disputed listing has somebody
 * locked out of their own business, and an ordinary claim only has somebody
 * waiting. The wait is shown in days on every row for the same reason: a queue
 * that does not say how long the oldest item has been there is a queue that
 * quietly grows a tail.
 */
export default function Claims({ disputes, claims }: Props) {
    return (
        <ConsoleShell current="claims">
            <Head title="Claims" />

            <div className="mx-auto max-w-5xl px-6 pb-20">
                <header className="mt-10 mb-8">
                    <h1 className="font-display text-display-l text-ink">
                        Claims
                    </h1>
                    <p className="mt-2 max-w-prose text-body text-muted">
                        A claim that could be settled by the number an officer
                        recorded has already settled itself. What reaches this
                        queue is what a rule could not decide.
                    </p>
                </header>

                {disputes.length > 0 && (
                    <section className="mb-12">
                        <h2 className="mb-1 font-display text-display-s text-ink">
                            Disputed listings
                        </h2>
                        <p className="mb-5 text-ui text-muted">
                            Two parties, one listing. The incumbent keeps
                            control until you decide.
                        </p>
                        <ul className="flex flex-col gap-5">
                            {disputes.map((dispute) => (
                                <DisputeRow
                                    key={dispute.id}
                                    dispute={dispute}
                                />
                            ))}
                        </ul>
                    </section>
                )}

                <section>
                    <h2 className="mb-1 font-display text-display-s text-ink">
                        Awaiting review
                    </h2>
                    <p className="mb-5 text-ui text-muted">
                        {claims.length === 0
                            ? "Nothing waiting."
                            : `${String(claims.length)} claim${claims.length === 1 ? "" : "s"}, oldest first.`}
                    </p>

                    <ul className="flex flex-col">
                        {claims.map((claim) => (
                            <ClaimRow key={claim.id} claim={claim} />
                        ))}
                    </ul>
                </section>
            </div>
        </ConsoleShell>
    );
}

/**
 * Evidence, with what it is worth said on the row.
 *
 * A reviewer reading "location at claim time: 30 m" needs to know on the screen
 * that 30 metres decides nothing, not from having been told once in training.
 * Supporting signals are labelled supporting, every time they are drawn.
 */
function Signals({ signals }: { signals: Signal[] }) {
    if (signals.length === 0) {
        return (
            <p className="text-label text-faint">
                Nothing offered but the assertion itself.
            </p>
        );
    }

    return (
        <ul className="flex flex-col gap-1">
            {signals.map((signal) => (
                <li key={signal.kind} className="text-label">
                    <span
                        className={
                            signal.result === "confirmed" &&
                            !signal.supportingOnly
                                ? "text-green"
                                : "text-muted"
                        }
                    >
                        {signal.label}: {signal.result}
                    </span>
                    {signal.detail !== null && (
                        <span className="text-faint"> · {signal.detail}</span>
                    )}
                    {signal.supportingOnly && (
                        <span className="text-faint">
                            {" "}
                            · supporting only, never decisive
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

function ClaimRow({ claim }: { claim: QueueClaim }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ decision: "approve", note: "" });
    const days = waitingDays(claim.assertedAt);

    return (
        <li className="border-b border-rule py-5">
            <div className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                <div className="min-w-0">
                    <h3 className="font-display text-display-s text-ink">
                        {claim.tradingName}
                    </h3>
                    <p className="mt-1 text-ui text-muted">
                        {claim.party.name} ({claim.party.code}) ·{" "}
                        {claim.relationship}
                        {claim.ward !== null && ` · ${claim.ward}`}
                    </p>
                    <div className="mt-2">
                        <Signals signals={claim.signals} />
                    </div>
                </div>
                <div className="flex items-center gap-4">
                    <span className="numeric-mono text-label text-faint">
                        {on(claim.assertedAt)} · {days} d
                    </span>
                    <Button
                        onClick={() => {
                            setOpen(!open);
                        }}
                    >
                        {open ? "Close" : "Decide"}
                    </Button>
                </div>
            </div>

            {open && (
                <form
                    className="mt-5 flex max-w-prose flex-col gap-3 border-l-2 border-gold bg-raised py-4 pr-4 pl-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/console/claims/${String(claim.id)}`);
                    }}
                >
                    <div className="flex gap-4">
                        {(["approve", "reject"] as const).map((option) => (
                            <label
                                key={option}
                                className="flex items-center gap-2 text-ui text-ink"
                            >
                                <input
                                    type="radio"
                                    name={`decision-${String(claim.id)}`}
                                    checked={form.data.decision === option}
                                    onChange={() => {
                                        form.setData("decision", option);
                                    }}
                                />
                                {option === "approve" ? "Approve" : "Reject"}
                            </label>
                        ))}
                    </div>

                    <label className="flex flex-col gap-1.5">
                        <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                            Why
                        </span>
                        <textarea
                            value={form.data.note}
                            onChange={(e) => {
                                form.setData("note", e.target.value);
                            }}
                            rows={3}
                            className="rounded-sm border border-rule-strong bg-surface px-3 py-2 text-ui text-ink"
                        />
                    </label>
                    {/* Required on both paths. It is the approvals that get
                        questioned a year later, not the rejections. */}
                    <p className="text-label text-faint">
                        Recorded against the listing, either way.
                    </p>
                    {form.errors.note !== undefined && (
                        <p className="text-ui text-alert">{form.errors.note}</p>
                    )}
                    {form.errors.decision !== undefined && (
                        <p className="text-ui text-alert">
                            {form.errors.decision}
                        </p>
                    )}

                    <div>
                        <Button
                            type="submit"
                            variant="primary"
                            busy={form.processing}
                        >
                            Record decision
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}

function DisputeRow({ dispute }: { dispute: Dispute }) {
    const form = useForm({ resolution: "upheld_incumbent", note: "" });

    return (
        <li className="rounded-sm border border-rule-strong p-5">
            <div className="flex flex-wrap items-baseline justify-between gap-3">
                <h3 className="font-display text-display-s text-ink">
                    {dispute.tradingName}
                </h3>
                <span className="numeric-mono text-label text-faint">
                    opened {on(dispute.openedAt)} ·{" "}
                    {waitingDays(dispute.openedAt)} d
                </span>
            </div>

            <div className="mt-4 grid gap-5 sm:grid-cols-2">
                <div className="border-l-2 border-held pl-4">
                    <p className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        Holds it now
                    </p>
                    <p className="mt-1 text-ui text-ink">
                        {dispute.incumbent.name}
                    </p>
                    <p className="numeric-mono text-label text-faint">
                        {dispute.incumbent.code}
                    </p>
                    <p className="mt-1 text-label text-muted">
                        since {on(dispute.incumbent.heldSince)} · via{" "}
                        {dispute.incumbent.via.replace(/_/g, " ")}
                    </p>
                </div>

                <div className="border-l-2 border-gold pl-4">
                    <p className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        Challenging
                    </p>
                    <p className="mt-1 text-ui text-ink">
                        {dispute.challenger.name}
                    </p>
                    <p className="numeric-mono text-label text-faint">
                        {dispute.challenger.code}
                    </p>
                    <p className="mt-1 text-label text-muted">
                        says they are the {dispute.challenger.relationship}
                    </p>
                    <div className="mt-2">
                        <Signals signals={dispute.challenger.signals} />
                    </div>
                </div>
            </div>

            <form
                className="mt-5 flex flex-col gap-3 border-t border-rule pt-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/console/disputes/${String(dispute.id)}`);
                }}
            >
                <label className="flex flex-col gap-1.5">
                    <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        Resolution
                    </span>
                    <select
                        value={form.data.resolution}
                        onChange={(e) => {
                            form.setData("resolution", e.target.value);
                        }}
                        className="h-9 rounded-sm border border-rule-strong bg-surface px-3 text-ui text-ink"
                    >
                        <option value="upheld_incumbent">
                            Leave it with {dispute.incumbent.name}
                        </option>
                        <option value="transferred_to_challenger">
                            Transfer to {dispute.challenger.name}
                        </option>
                        <option value="withdrawn">
                            The challenger withdrew
                        </option>
                    </select>
                </label>

                <label className="flex flex-col gap-1.5">
                    <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        Why
                    </span>
                    <textarea
                        value={form.data.note}
                        onChange={(e) => {
                            form.setData("note", e.target.value);
                        }}
                        rows={3}
                        className="rounded-sm border border-rule-strong bg-surface px-3 py-2 text-ui text-ink"
                    />
                </label>

                {form.errors.note !== undefined && (
                    <p className="text-ui text-alert">{form.errors.note}</p>
                )}
                {form.errors.resolution !== undefined && (
                    <p className="text-ui text-alert">
                        {form.errors.resolution}
                    </p>
                )}

                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        busy={form.processing}
                    >
                        Resolve
                    </Button>
                </div>
            </form>
        </li>
    );
}
