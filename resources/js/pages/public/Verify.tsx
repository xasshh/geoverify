import { Head } from "@inertiajs/react";
import { GeoVerifyMark } from "@/components/GeoVerifyMark";

/**
 * What somebody sees after scanning the QR on a certificate.
 *
 * Reached with no account by a person deciding whether to trust a business, so
 * it is written for a stranger holding a piece of paper rather than for a user
 * of this system. The plainest possible answer comes first and the caveats come
 * with it rather than below the fold: a page that says "verified" in large type
 * and explains what that means in small type is a page designed to be
 * misunderstood.
 */

type Freshness = "current" | "ageing" | "stale";

interface Result {
    state: "valid" | "expired" | "revoked" | "unknown";
    reference?: string;
    business?: string;
    ward?: string | null;
    lga?: string | null;
    state_name?: string | null;
    finding?: string;
    tier?: string;
    verified_on?: string;
    valid_until?: string | null;
    freshness?: Freshness;
    officer_reference?: string | null;
    kind?: "report";
    final?: boolean;
    score?: number;
}

interface Props {
    result: Result;
}

function on(date?: string | null): string {
    if (!date) {
        return "an unrecorded date";
    }

    return new Date(date).toLocaleDateString("en-NG", {
        day: "numeric",
        month: "long",
        year: "numeric",
    });
}

const FRESHNESS_NOTE: Record<Freshness, string> = {
    current: "This check is recent.",
    ageing: "This check is over a year old.",
    stale: "This check is old. Ask the business for a fresh one.",
};

function Fact({ term, children, mono }: { term: string; children: React.ReactNode; mono?: boolean }) {
    return (
        <div className="flex flex-col gap-1 border-b border-rule py-2.5 last:border-b-0 sm:border-b-0">
            <dt className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                {term}
            </dt>
            <dd className={mono ? "numeric-mono text-mono text-ink" : "text-ui text-ink"}>
                {children}
            </dd>
        </div>
    );
}

export default function Verify({ result }: Props) {
    const found = result.state === "valid" || result.state === "expired";

    const place = [result.ward, result.lga, result.state_name]
        .filter((part): part is string => typeof part === "string" && part !== "")
        .join(", ");

    return (
        <div className="min-h-dvh bg-surface px-4 py-8 sm:py-12">
            <Head
                title={
                    result.reference === undefined
                        ? "Certificate check"
                        : `${result.kind === "report" ? "Report" : "Certificate"} ${result.reference}`
                }
            />

            <main className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                <header className="flex items-center gap-3 border-b-2 border-ink pb-4">
                    <GeoVerifyMark size={32} title="GeoVerify" />
                    <div className="flex flex-col">
                        <span className="font-display text-display-s text-ink">GeoVerify</span>
                        <span className="text-label tracking-[0.05em] text-faint uppercase">
                            Certificate check
                        </span>
                    </div>
                </header>

                {result.state === "unknown" && (
                    <section className="rounded-sm bg-alert-soft flex flex-col gap-3 px-5 py-4">
                        <h1 className="font-display text-display-m text-alert">
                            We have no record of this certificate
                        </h1>
                        <p className="text-body text-muted">
                            Nothing in this register matches that code. Check that you have typed
                            it exactly as printed. A certificate we did not issue is not evidence
                            of anything, whatever it looks like.
                        </p>
                    </section>
                )}

                {result.state === "revoked" && (
                    <section className="rounded-sm bg-alert-soft flex flex-col gap-3 px-5 py-4">
                        <h1 className="font-display text-display-m text-alert">
                            This certificate has been withdrawn
                        </h1>
                        <p className="text-body text-muted">
                            We issued it and we have since taken it back, which happens when a
                            finding is overturned or a document was issued in error. Do not rely
                            on it.
                        </p>
                    </section>
                )}

                {found && result.kind === "report" && (
                    <>
                        <section className="flex flex-col gap-2 border-l-2 border-green bg-raised px-5 py-5">
                            <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                This Enumerate report is genuine. {result.final === true ? "Its finding:" : "It is an interim report. So far:"}
                            </p>
                            <h1 className="font-display text-display-l text-ink">
                                {result.finding}
                                {result.score !== undefined && <span className="text-muted"> · {result.score}/100</span>}
                            </h1>
                            <p className="text-display-s font-display text-ink">{result.business}</p>
                            {place !== "" && <p className="text-ui text-muted">{place}</p>}
                        </section>

                        <dl className="grid gap-x-6 sm:grid-cols-2">
                            <Fact term="Check">{result.tier}</Fact>
                            <Fact term="Reference" mono>{result.reference}</Fact>
                            <Fact term={result.final === true ? "Completed on" : "Last updated"}>{on(result.verified_on)}</Fact>
                            <Fact term="Attending officer" mono>{result.officer_reference ?? "No visit in this check"}</Fact>
                        </dl>

                        <section className="flex flex-col gap-3 border-t border-rule pt-5">
                            <h2 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">What this does and does not tell you</h2>
                            <p className="text-ui text-muted">
                                Somebody paid GeoVerify to check this business. We read its record at the Corporate Affairs Commission
                                and FIRS{result.officer_reference ? ", and an officer went to the address and wrote down what they found" : ""}.
                                The score summarises those findings; the printed report says what each part was for.
                            </p>
                            <p className="text-ui text-muted">
                                <strong className="text-ink">This is not a government approval, a licence or a recommendation.</strong>{" "}
                                A business can move, close or change hands after a check, and this page cannot know that.
                            </p>
                        </section>
                    </>
                )}

                {found && result.kind !== "report" && (
                    <>
                        <section
                            className={`flex flex-col gap-2 border-l-2 bg-raised px-5 py-5 ${
                                result.state === "expired" ? "border-amber" : "border-green"
                            }`}
                        >
                            <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                This certificate is genuine. It says:
                            </p>
                            <h1 className="font-display text-display-l text-ink">
                                {result.finding}
                            </h1>
                            <p className="text-display-s font-display text-ink">
                                {result.business}
                            </p>
                            {place !== "" && <p className="text-ui text-muted">{place}</p>}
                        </section>

                        <dl className="grid gap-x-6 sm:grid-cols-2">
                            <Fact term="Visited on">{on(result.verified_on)}</Fact>
                            <Fact term="Reference" mono>
                                {result.reference}
                            </Fact>
                            <Fact term="Attending officer" mono>
                                {result.officer_reference}
                            </Fact>
                            <Fact term="Status">
                                {result.state === "expired"
                                    ? "Past its validity date"
                                    : FRESHNESS_NOTE[result.freshness ?? "current"]}
                            </Fact>
                        </dl>

                        <section className="flex flex-col gap-3 border-t border-rule pt-5">
                            <h2 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                What this does and does not tell you
                            </h2>
                            <p className="text-ui text-muted">
                                An officer of this register went to the address recorded for this
                                business on {on(result.verified_on)}, took a satellite position on
                                the spot, and wrote down what they found. The ward and local
                                government above were worked out from that position against
                                official boundaries, not from anything the business told us.
                            </p>
                            <p className="text-ui text-muted">
                                <strong className="text-ink">
                                    This is not a government approval, a licence or a
                                    recommendation.
                                </strong>{" "}
                                Nobody has vouched for how this business trades, whether it pays
                                its debts or whether you should buy from it. What was checked is
                                that it existed, at that place, on that day.
                            </p>
                            <p className="text-ui text-muted">
                                A business can move, close or change hands the day after a visit,
                                and this page cannot know that.
                                {result.valid_until
                                    ? ` We stop treating this check as current after ${on(result.valid_until)}.`
                                    : ""}
                            </p>
                        </section>
                    </>
                )}

                <footer className="border-t border-rule pt-4 text-table text-faint">
                    We do not show the phone number, the exact coordinates or the photographs
                    recorded at the visit. Those belong to the business.
                </footer>
            </main>
        </div>
    );
}
