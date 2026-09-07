import { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import { Button } from "@/components/Button";
import { SelectField, TextField } from "@/components/Field";
import { StatusPill } from "@/components/StatusPill";
import { PortalShell } from "@/components/PortalShell";
import { VerificationLadder } from "@/components/VerificationLadder";
import { StatusPill as OrderPill } from "@/components/StatusPill";
import { orderTone, type OrderStatus } from "@/lib/status";
import type { Rung } from "@/lib/tiers";

interface Props {
    business: {
        id: number;
        tradingName: string;
        sector: string | null;
        structureType: string;
        ward: string | null;
        lga: string | null;
        enumeratedAt: string;
        selfRegistered: boolean;
    };
    rungs: Rung[];
    control: { relationship: string; since: string; via: string };
    observations: {
        observedAt: string;
        tradingName: string;
        operatingStatus: string;
        signageObserved: boolean;
        hasPhone: boolean;
    }[];
    party: { code: string | null; displayName: string | null };
    nextRung: {
        tier: string;
        label: string;
        feeNaira: number;
        within: string;
    } | null;
    orders: {
        id: number;
        reference: string;
        tier: string;
        status: OrderStatus;
        statusLabel: string;
        dueBy: string | null;
        feeNaira: number;
    }[];
    corrections: Correction[];
    correctableFields: { value: string; label: string }[];
    publication: {
        state: string;
        label: string;
        explanation: string;
        decidedAt: string | null;
    };
}

interface Correction {
    id: number;
    field: string;
    fieldLabel: string;
    currentValue: string | null;
    proposedValue: string | null;
    reason: string;
    status: string;
    statusLabel: string;
    proposedAt: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
}

function monthOf(iso: string): string {
    return new Date(iso).toLocaleDateString("en-NG", {
        month: "long",
        year: "numeric",
    });
}

/**
 * The listing a party controls.
 *
 * The ladder is the page's spine, and it is resolved on the server rather than
 * assembled here. Two routes reach this page now: a business an officer stood
 * in front of, and one that registered itself. They have established different
 * things, and a page that decided which by reading its own props would sooner
 * or later credit a self-registration with a visit nobody made.
 *
 * There is no edit form, and the reason is on the page rather than implied. A
 * party who could rewrite an officer's observation would be able to keep the
 * credibility of a field visit while changing what it found.
 */

/**
 * Proposing a correction, and what has been proposed before.
 *
 * A form that sends a message, not a form that edits a record. The distinction
 * is the whole milestone, so the page says it in words as well as in structure:
 * what an officer wrote stays, the business's account is appended beside it, and
 * a person decides.
 *
 * Settled corrections stay on the page with the reason they were settled. A
 * business told no deserves to see why, and one told yes deserves to see that it
 * landed rather than wondering whether the form worked.
 */
function CorrectionPanel({
    business,
    corrections,
    correctableFields,
}: {
    business: Props["business"];
    corrections: Correction[];
    correctableFields: { value: string; label: string }[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        field: correctableFields[0]?.value ?? "",
        proposed_value: "",
        reason: "",
    });

    return (
        <section className="rounded-sm border border-rule p-5">
            <h2 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                Changing what this says
            </h2>

            {/* Both records are append only, for different reasons. An
                officer's is somebody else's account of a morning. Yours is your
                own, but a register whose entries could be quietly rewritten
                after the fact would be worth nothing to anybody reading it. */}
            <p className="mt-2 text-body text-muted">
                {business.selfRegistered
                    ? "What you have already told us stays on the record. If something changed, or you got something wrong, propose a correction: it sits beside the original rather than replacing it."
                    : "An officer's record of a visit is not editable, by you or by us. If something here is wrong, propose a correction: the original stays, yours sits beside it, and a reviewer decides."}
            </p>

            {open ? (
                <form
                    className="mt-4 flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(
                            `/portal/businesses/${String(business.id)}/corrections`,
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    setOpen(false);
                                },
                            },
                        );
                    }}
                >
                    <SelectField
                        label="What is wrong"
                        value={form.data.field}
                        onChange={(event) => {
                            form.setData("field", event.target.value);
                        }}
                    >
                        {correctableFields.map((field) => (
                            <option key={field.value} value={field.value}>
                                {field.label}
                            </option>
                        ))}
                    </SelectField>

                    <TextField
                        label="What it should say"
                        value={form.data.proposed_value}
                        onChange={(event) => {
                            form.setData("proposed_value", event.target.value);
                        }}
                    />

                    <label className="flex flex-col gap-1">
                        <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                            Why
                        </span>
                        <textarea
                            rows={3}
                            value={form.data.reason}
                            onChange={(event) => {
                                form.setData("reason", event.target.value);
                            }}
                            className="w-full rounded-sm border border-rule-strong bg-surface px-3 py-2 text-ui text-ink"
                        />
                    </label>

                    {Object.values(form.errors).map((error) => (
                        <p key={error} className="text-label text-alert">
                            {error}
                        </p>
                    ))}

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" busy={form.processing}>
                            Send for review
                        </Button>
                        <button
                            type="button"
                            onClick={() => {
                                setOpen(false);
                            }}
                            className="text-ui text-muted underline underline-offset-2"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <div className="mt-4">
                    <Button
                        variant="secondary"
                        onClick={() => {
                            setOpen(true);
                        }}
                    >
                        Propose a correction
                    </Button>
                </div>
            )}

            {corrections.length > 0 && (
                <ul className="mt-5 flex flex-col gap-3 border-t border-rule pt-4">
                    {corrections.map((correction) => (
                        <li key={correction.id} className="flex flex-col gap-1">
                            <span className="flex flex-wrap items-baseline justify-between gap-2">
                                <span className="text-ui text-ink">
                                    {correction.fieldLabel}:{" "}
                                    {correction.proposedValue ?? "cleared"}
                                </span>
                                <StatusPill
                                    tone={
                                        correction.status === "accepted"
                                            ? "accepted"
                                            : correction.status === "submitted"
                                              ? "review"
                                              : "rejected"
                                    }
                                    label={correction.statusLabel}
                                    size="sm"
                                />
                            </span>

                            {correction.decisionNote !== null && (
                                <span className="text-label text-muted">
                                    {correction.decisionNote}
                                </span>
                            )}

                            {correction.status === "submitted" && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        router.post(
                                            `/portal/corrections/${String(correction.id)}/withdraw`,
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }}
                                    className="self-start text-label text-gold underline underline-offset-2"
                                >
                                    Withdraw
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Whether this listing may be shown outside the register.
 *
 * Nobody was asked at the doorstep, so the answer starts as no and only a party
 * can change it. Withdrawing takes effect at once: a business that has changed
 * its mind has changed its mind, and there is no window in which we keep
 * publishing anyway.
 */
function PublicationPanel({
    business,
    publication,
}: {
    business: Props["business"];
    publication: Props["publication"];
}) {
    const choose = (state: "opted_in" | "withheld") => {
        router.post(
            `/portal/businesses/${String(business.id)}/publication`,
            { state },
            { preserveScroll: true },
        );
    };

    return (
        <section className="rounded-sm border border-rule p-5">
            <h2 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                Showing this publicly
            </h2>

            <p className="mt-2 text-body text-muted">
                {publication.explanation}
            </p>

            <div className="mt-4 flex flex-wrap gap-3">
                <Button
                    variant={
                        publication.state === "opted_in"
                            ? "primary"
                            : "secondary"
                    }
                    onClick={() => {
                        choose("opted_in");
                    }}
                >
                    Publish my listing
                </Button>
                <Button
                    variant={
                        publication.state === "withheld"
                            ? "primary"
                            : "secondary"
                    }
                    onClick={() => {
                        choose("withheld");
                    }}
                >
                    Keep it off
                </Button>
            </div>

            <p className="mt-3 text-label text-faint">
                Being on the register and being shown publicly are different
                things. Nothing here changes what we hold or what a mandate can
                see.
            </p>
        </section>
    );
}

export default function Listing({
    business,
    rungs,
    control,
    observations,
    party,
    corrections,
    correctableFields,
    publication,
    nextRung,
    orders,
}: Props) {
    return (
        <PortalShell accountName={party.displayName} width="page">
            <Head title={business.tradingName} />

            <div className="mt-10 mb-8">
                <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                    Your business
                </p>
                <h1 className="mt-1 font-display text-display-l text-ink">
                    {business.tradingName}
                </h1>
                <p className="mt-2 text-ui text-muted">
                    {[business.ward, business.lga].filter(Boolean).join(", ")} ·{" "}
                    {business.structureType.replace(/_/g, " ")}
                </p>
                <p className="mt-1 numeric-mono text-label text-faint">
                    You have managed this as {control.relationship} since{" "}
                    {monthOf(control.since)}
                </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                <section className="rounded-sm border border-rule-strong p-5">
                    <h2 className="mb-4 font-display text-display-s text-ink">
                        What is established
                    </h2>
                    <VerificationLadder rungs={rungs} />

                    {/* The offer sits under the ladder rather than in a banner,
                        because the ladder is the argument for it: a party looks
                        at what is not established yet and the next line tells
                        them what establishing it costs. */}
                    {nextRung !== null && (
                        <div className="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-sm border border-rule-strong bg-sunken px-4 py-3.5">
                            <div>
                                <p className="text-ui text-ink">
                                    Establish {nextRung.label.toLowerCase()}
                                </p>
                                <p className="numeric-mono text-label text-muted">
                                    ₦{nextRung.feeNaira.toLocaleString("en-NG")}{" "}
                                    · within {nextRung.within}
                                </p>
                            </div>
                            <Button
                                onClick={() => {
                                    router.get(
                                        `/portal/businesses/${String(business.id)}/verify/${nextRung.tier}`,
                                    );
                                }}
                            >
                                See what it involves
                            </Button>
                        </div>
                    )}

                    {orders.length > 0 && (
                        <div className="mt-6">
                            <h3 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                Verifications you have bought
                            </h3>
                            <ul className="mt-2 flex flex-col">
                                {orders.map((order) => (
                                    <li
                                        key={order.id}
                                        className="flex flex-wrap items-center justify-between gap-3 border-b border-rule py-2.5 last:border-b-0"
                                    >
                                        <a
                                            href={`/portal/orders/${String(order.id)}`}
                                            className="text-ui text-ink underline underline-offset-4"
                                        >
                                            {order.tier}
                                        </a>
                                        <span className="numeric-mono text-label text-faint">
                                            {order.reference}
                                        </span>
                                        <OrderPill
                                            label={order.statusLabel}
                                            tone={orderTone(order.status)}
                                            size="sm"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>

                <aside className="flex flex-col gap-4">
                    <section className="rounded-sm border border-rule p-5">
                        <h2 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                            {business.selfRegistered
                                ? "What you told us"
                                : "What an officer recorded"}
                        </h2>
                        <ul className="mt-3 flex flex-col gap-3">
                            {observations.map((observation) => (
                                <li
                                    key={observation.observedAt}
                                    className="border-b border-rule pb-3 last:border-b-0 last:pb-0"
                                >
                                    <p className="numeric-mono text-label text-faint">
                                        {new Date(
                                            observation.observedAt,
                                        ).toLocaleDateString("en-NG", {
                                            day: "numeric",
                                            month: "long",
                                            year: "numeric",
                                        })}
                                    </p>
                                    <p className="mt-1 text-ui text-ink">
                                        {observation.tradingName}
                                    </p>
                                    <p className="mt-0.5 text-label text-muted">
                                        {observation.operatingStatus.replace(
                                            /_/g,
                                            " ",
                                        )}
                                        {observation.signageObserved &&
                                            " · signage seen"}
                                        {observation.hasPhone &&
                                            " · phone recorded"}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <CorrectionPanel
                        business={business}
                        corrections={corrections}
                        correctableFields={correctableFields}
                    />

                    <PublicationPanel
                        business={business}
                        publication={publication}
                    />
                </aside>
            </div>
        </PortalShell>
    );
}
