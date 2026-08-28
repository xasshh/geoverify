import { Head } from "@inertiajs/react";
import { PortalShell } from "@/components/PortalShell";
import { VerificationLadder } from "@/components/VerificationLadder";
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
export default function Listing({
    business,
    rungs,
    control,
    observations,
    party,
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

                    <section className="rounded-sm border border-rule p-5">
                        <h2 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                            Changing what this says
                        </h2>
                        {/* Both records are append only, for different
                            reasons. An officer's is somebody else's account of
                            a morning. Yours is your own, but a register whose
                            entries could be quietly rewritten after the fact
                            would be worth nothing to anybody reading it. */}
                        <p className="mt-2 text-body text-muted">
                            {business.selfRegistered
                                ? "What you have already told us stays on the record. If something changes, or you got something wrong, you will be able to add a correction beside it rather than overwrite it."
                                : "An officer's record of a visit is not editable, by you or by us. If something here is wrong, you will be able to propose a correction: the original stays, your correction sits beside it, and a reviewer decides."}
                        </p>
                        <p className="mt-3 text-label text-faint">
                            Corrections open in the next release.
                        </p>
                    </section>
                </aside>
            </div>
        </PortalShell>
    );
}
