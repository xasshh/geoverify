import { Head, Link, useForm, usePage } from "@inertiajs/react";
import { PortalShell } from "@/components/PortalShell";
import { TextField } from "@/components/Field";
import { Button } from "@/components/Button";
import { StatusPill } from "@/components/StatusPill";
import type { StatusTone } from "@/lib/status";

interface Props {
    claim: {
        id: number;
        status: string;
        statusLabel: string;
        relationship: string;
        assertedAt: string;
        decision: string | null;
        decisionNote: string | null;
        phoneConfirmed: boolean;
    };
    business: {
        id: number;
        tradingName: string;
        structureType: string;
        ward: string | null;
        lga: string | null;
    };
    recordedPhoneHint: string | null;
    dispute: { openedAt: string; resolution: string | null } | null;
    party: { code: string | null };
}

const TONE: Record<string, StatusTone> = {
    submitted: "review",
    approved: "accepted",
    rejected: "rejected",
    disputed: "held",
    withdrawn: "idle",
};

/**
 * Where a claim stands, and the one thing that can move it.
 *
 * Each state gets a single next action rather than a panel of everything
 * possible. Somebody who has just asserted that a shop is theirs wants to know
 * whether it worked, and if not, what to do about it: a screen offering four
 * routes at once answers neither question.
 */
export default function ClaimShow({
    claim,
    business,
    recordedPhoneHint,
    dispute,
    party,
}: Props) {
    const flash = usePage().props.flash as
        { status?: string | null } | undefined;
    const status =
        typeof flash?.status === "string" && flash.status !== ""
            ? flash.status
            : null;

    return (
        <PortalShell accountName={party.code} width="form">
            <Head title={`Claim: ${business.tradingName}`} />

            {status !== null && (
                <p className="mt-8 border-l-2 border-green bg-raised px-4 py-3 text-body text-ink">
                    {status}
                </p>
            )}

            <div className="mt-10 mb-6">
                <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                    Your claim
                </p>
                <h1 className="mt-1 font-display text-display-l text-ink">
                    {business.tradingName}
                </h1>
                <p className="mt-2 text-ui text-muted">
                    {[business.ward, business.lga].filter(Boolean).join(", ")} ·{" "}
                    {business.structureType.replace(/_/g, " ")}
                </p>
            </div>

            <div className="flex items-center gap-3 border-y border-rule py-4">
                <StatusPill
                    tone={TONE[claim.status] ?? "idle"}
                    label={claim.statusLabel}
                    emphasis="filled"
                />
                <span className="text-ui text-muted">
                    You said: {claim.relationship}
                </span>
            </div>

            {claim.status === "approved" && (
                <section className="mt-8">
                    <h2 className="font-display text-display-s text-ink">
                        This business is yours
                    </h2>
                    <p className="mt-2 text-body text-muted">
                        You can manage the listing now.
                        {claim.decision === "auto_phone_match" &&
                            " You proved it with the number the officer recorded."}
                    </p>
                    <p className="mt-5">
                        <Link
                            href={`/portal/businesses/${String(business.id)}`}
                            className="text-body text-gold underline underline-offset-4"
                        >
                            Open the listing
                        </Link>
                    </p>
                </section>
            )}

            {claim.status === "rejected" && (
                <section className="mt-8">
                    <h2 className="font-display text-display-s text-ink">
                        Not approved
                    </h2>
                    {claim.decisionNote !== null && (
                        <p className="mt-2 text-body text-ink">
                            {claim.decisionNote}
                        </p>
                    )}
                    <p className="mt-3 text-body text-muted">
                        If you have something else that shows you run this
                        business, claim it again with that attached.
                    </p>
                </section>
            )}

            {claim.status === "disputed" && dispute !== null && (
                <section className="mt-8">
                    <h2 className="font-display text-display-s text-ink">
                        Someone else holds this listing
                    </h2>
                    <p className="mt-2 text-body text-ink">
                        A reviewer will read both claims and decide. We will
                        tell you the outcome either way.
                    </p>
                    <p className="mt-3 text-body text-muted">
                        They keep managing it until then, so that nobody can
                        take a listing offline just by claiming it. If the
                        decision goes your way, control moves to you and theirs
                        ends the same moment.
                    </p>
                    <p className="mt-4 numeric-mono text-label text-faint">
                        Opened{" "}
                        {new Date(dispute.openedAt).toLocaleDateString("en-NG")}
                    </p>
                </section>
            )}

            {claim.status === "submitted" &&
                (recordedPhoneHint !== null ? (
                    <ProveByPhone claimId={claim.id} hint={recordedPhoneHint} />
                ) : (
                    <section className="mt-8">
                        <h2 className="font-display text-display-s text-ink">
                            A reviewer will look at this
                        </h2>
                        <p className="mt-2 text-body text-muted">
                            No phone number was recorded for this business, so
                            there is nothing to send a code to. Someone will
                            read your claim and decide.
                        </p>
                    </section>
                ))}
        </PortalShell>
    );
}

/**
 * The fast path, and the only one that settles a claim on its own.
 *
 * The number is shown masked. The claimant has to recognise it rather than read
 * it, because a claim form that printed the number would be a way to collect a
 * phone number for any business in the register by starting a claim and walking
 * away.
 */
function ProveByPhone({ claimId, hint }: { claimId: number; hint: string }) {
    const send = useForm({});
    const confirm = useForm({ code: "" });

    return (
        <section className="mt-8">
            <h2 className="font-display text-display-s text-ink">
                Confirm it in a minute
            </h2>
            <p className="mt-2 text-body text-ink">
                The officer recorded a phone number for this business. We can
                send a code to it.
            </p>
            <p className="mt-4 numeric-mono text-display-s text-ink">{hint}</p>
            <p className="mt-1 text-label text-faint">
                We show only part of the number. If you do not recognise it, do
                not guess: ask for a reviewer instead.
            </p>

            <div className="mt-5">
                <Button
                    variant="secondary"
                    busy={send.processing}
                    onClick={() => {
                        send.post(`/portal/claim/${String(claimId)}/code`);
                    }}
                >
                    Send the code
                </Button>
            </div>

            <form
                className="mt-8 flex flex-col gap-4 border-t border-rule pt-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    confirm.post(`/portal/claim/${String(claimId)}/confirm`);
                }}
            >
                <TextField
                    label="The six digit code"
                    value={confirm.data.code}
                    onChange={(e) => {
                        confirm.setData(
                            "code",
                            e.target.value.replace(/\D/g, ""),
                        );
                    }}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    machine
                    {...(confirm.errors.code !== undefined && {
                        error: confirm.errors.code,
                    })}
                />
                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        busy={confirm.processing}
                    >
                        Confirm
                    </Button>
                </div>
            </form>
        </section>
    );
}
