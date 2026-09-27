import { Head } from "@inertiajs/react";
import { GeoVerifyMark } from "@/components/GeoVerifyMark";

/**
 * A person's own copy of what they agreed to.
 *
 * Reached on a token by somebody who may have no account here, so it is written
 * for a person checking a document rather than for a user of this system. The
 * words they were shown are reproduced verbatim and given more of the page than
 * anything else: a receipt that summarised the disclosure in its own words would
 * be answering "what did you agree to" with a paraphrase, which is the one thing
 * this document exists not to do.
 */

interface Receipt {
    state: "standing" | "withdrawn" | "withdrawal" | "refused" | "unknown";
    reference?: string;
    purpose?: string;
    subject?: string | null;
    disclosure?: string;
    disclosure_version?: string;
    lawful_basis?: string;
    scope?: string[];
    agreed_by?: string | null;
    agreed_as?: string;
    agreed_on?: string;
    withdrawn_on?: string | null;
}

interface Props {
    receipt: Receipt;
    token: string;
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

export default function Receipt({ receipt, token }: Props) {
    const found = receipt.state !== "unknown";

    return (
        <div className="min-h-dvh bg-surface px-4 py-8 sm:py-12">
            <Head
                title={
                    receipt.reference === undefined
                        ? "Consent receipt"
                        : `Consent receipt ${receipt.reference}`
                }
            />

            <main className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                <header className="flex items-center gap-3 border-b-2 border-ink pb-4">
                    <GeoVerifyMark size={32} title="GeoVerify" />
                    <div className="flex flex-col">
                        <span className="font-display text-display-s text-ink">GeoVerify</span>
                        <span className="text-label tracking-[0.05em] text-faint uppercase">
                            Consent receipt
                        </span>
                    </div>
                </header>

                {receipt.state === "unknown" && (
                    <section className="rounded-sm bg-alert-soft flex flex-col gap-3 px-5 py-4">
                        <h1 className="font-display text-display-m text-alert">
                            We have no record of this receipt
                        </h1>
                        <p className="text-body text-muted">
                            Nothing in this register matches that link. Check that you have
                            it exactly as it was given to you. If you agreed to something and
                            cannot find the receipt, ask us and we will look.
                        </p>
                    </section>
                )}

                {found && (
                    <>
                        <section
                            className={`flex flex-col gap-2 border-l-2 bg-raised px-5 py-5 ${
                                receipt.state === "standing" ? "border-green" : "border-amber"
                            }`}
                        >
                            <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                {receipt.purpose}
                            </p>
                            <h1 className="font-display text-display-l text-ink">
                                {receipt.state === "standing" && "This agreement stands"}
                                {receipt.state === "withdrawn" && "This agreement was withdrawn"}
                                {receipt.state === "withdrawal" && "This withdrew an earlier agreement"}
                                {receipt.state === "refused" && "This was declined"}
                            </h1>
                            {receipt.subject !== null && receipt.subject !== undefined && (
                                <p className="text-display-s font-display text-ink">
                                    {receipt.subject}
                                </p>
                            )}
                            <p className="text-ui text-muted">
                                {receipt.state === "withdrawn"
                                    ? `Agreed on ${on(receipt.agreed_on)} and withdrawn on ${on(receipt.withdrawn_on)}. Nothing agreed here is still being relied on.`
                                    : `Recorded on ${on(receipt.agreed_on)}.`}
                            </p>
                        </section>

                        <section className="flex flex-col gap-3">
                            <h2 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                The words you were shown
                            </h2>
                            <blockquote className="rounded-sm bg-gold-soft px-5 py-4 text-body text-ink">
                                {receipt.disclosure}
                            </blockquote>
                            <p className="text-table text-faint">
                                Wording {receipt.disclosure_version}, reproduced as it stood on the
                                day. If we have changed it since, this copy is unaffected.
                            </p>
                        </section>

                        {receipt.scope !== undefined && receipt.scope.length > 0 && (
                            <section className="flex flex-col gap-3">
                                <h2 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                    What it covered
                                </h2>
                                <ul className="flex flex-wrap gap-2">
                                    {receipt.scope.map((field) => (
                                        <li
                                            key={field}
                                            className="border border-rule px-3 py-1 text-ui text-ink"
                                        >
                                            {field}
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-table text-faint">
                                    Nothing outside this list was agreed here. If we ever want to
                                    publish more, we have to ask again.
                                </p>
                            </section>
                        )}

                        <dl className="grid gap-x-6 sm:grid-cols-2">
                            <Fact term="Reference" mono>
                                {receipt.reference}
                            </Fact>
                            <Fact term="Recorded on">{on(receipt.agreed_on)}</Fact>
                            <Fact term="Agreed by">
                                {receipt.agreed_by ?? "Not recorded"}
                            </Fact>
                            <Fact term="Lawful basis">{receipt.lawful_basis}</Fact>
                        </dl>

                        <a
                            href={`/receipts/${token}/copy.pdf`}
                            className="self-start border border-ink px-4 py-2.5 text-ui text-ink hover:bg-raised"
                        >
                            Download a copy
                        </a>

                        <section className="flex flex-col gap-3 border-t border-rule pt-5">
                            <h2 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                Changing your mind
                            </h2>
                            <p className="text-ui text-muted">
                                {receipt.state === "standing"
                                    ? "You can withdraw at any time from the business's page in the portal, and the listing comes down immediately. Withdrawing does not delete this receipt: it writes a second one saying you withdrew, because both facts are true and we keep both."
                                    : "This receipt stays here after the fact. We keep what was agreed and what happened to it, so the history can be read years later by somebody who was not there."}
                            </p>
                        </section>
                    </>
                )}

                <footer className="border-t border-rule pt-4 text-table text-faint">
                    Keep this link private. Anybody holding it can read this receipt.
                </footer>
            </main>
        </div>
    );
}
