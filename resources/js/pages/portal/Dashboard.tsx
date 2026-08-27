import { Head, Link, usePage } from "@inertiajs/react";
import { PortalShell } from "@/components/PortalShell";
import { VerificationLadder } from "@/components/VerificationLadder";
import type { Rung } from "@/lib/tiers";

interface PartySummary {
    id: number;
    code: string | null;
    displayName: string | null;
    kind: string | null;
    role: string;
    identityTier: string | null;
    listings: number;
}

interface Listing {
    enterpriseId: number;
    tradingName: string;
    ward: string | null;
    since: string;
}

interface OpenClaim {
    id: number;
    tradingName: string;
    status: string;
    statusLabel: string;
    assertedAt: string;
}

interface DashboardProps {
    account: { name: string; phone: string };
    parties: PartySummary[];
    listings: Listing[];
    openClaims: OpenClaim[];
}

/** The ladder for a party that has only told us it exists. */
function ladderFor(party: PartySummary): Rung[] {
    const listed: Rung["state"] = "current";
    const identity: Rung["state"] =
        party.identityTier === "identity_verified"
            ? "current"
            : "not_established";

    return [
        { tier: "listed", state: listed, establishedOn: "today" },
        { tier: "identity_verified", state: identity },
        { tier: "location_verified", state: "not_established" },
        { tier: "operations_verified", state: "not_established" },
        { tier: "monitored", state: "not_established" },
    ];
}

/**
 * What a party sees when they arrive.
 *
 * At this milestone there is nothing claimed and nothing registered, so this is
 * the empty case, and the empty case is designed rather than left over. It
 * states the code, says plainly that no business is attached yet, and offers
 * the two things that can be done about it. It does not apologise, it does not
 * congratulate, and it does not dress an empty account up as progress.
 *
 * The single-party layout is the default. A person acting for several parties
 * gets a list; the person with one gets a page about that one.
 */
export default function Dashboard({
    account,
    parties,
    listings,
    openClaims,
}: DashboardProps) {
    // The shared props always carry a flash key, and its value is null when
    // there is nothing to say. Testing for undefined alone renders an empty
    // banner on every ordinary page load.
    const flash = usePage().props.flash as
        { status?: string | null } | undefined;
    const status =
        typeof flash?.status === "string" && flash.status !== ""
            ? flash.status
            : null;
    // noUncheckedIndexedAccess is on, so the length check alone does not
    // narrow the element type. Reading it once and testing the value does.
    const single = parties.length === 1 ? (parties[0] ?? null) : null;

    return (
        <PortalShell accountName={account.name} width="page">
            <Head title="Your account" />

            {status !== null && (
                <p className="mt-8 border-l-2 border-green bg-raised px-4 py-3 text-body text-ink">
                    {status}
                </p>
            )}

            {single !== null ? (
                <>
                    <div className="mt-10 mb-8">
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            {single.kind} · {single.role}
                        </p>
                        <h1 className="mt-1 font-display text-display-l text-ink">
                            {single.displayName}
                        </h1>
                        <p className="mt-2 numeric-mono text-mono text-muted">
                            {single.code}
                        </p>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                        <section className="rounded-sm border border-rule-strong p-5">
                            <h2 className="mb-4 font-display text-display-s text-ink">
                                What is established
                            </h2>
                            <VerificationLadder rungs={ladderFor(single)} />
                        </section>

                        <aside className="flex flex-col gap-4">
                            <section className="rounded-sm border border-rule-strong p-5">
                                <h2 className="font-display text-display-s text-ink">
                                    {listings.length === 0
                                        ? "No business attached yet"
                                        : listings.length === 1
                                          ? "Your business"
                                          : "Your businesses"}
                                </h2>

                                {listings.length === 0 ? (
                                    <p className="mt-2 text-body text-muted">
                                        If an officer has already recorded your
                                        business, claim it.
                                    </p>
                                ) : (
                                    <ul className="mt-3 flex flex-col gap-2.5">
                                        {listings.map((listing) => (
                                            <li key={listing.enterpriseId}>
                                                <Link
                                                    href={`/portal/businesses/${String(listing.enterpriseId)}`}
                                                    className="text-body text-ink underline underline-offset-4 hover:text-gold"
                                                >
                                                    {listing.tradingName}
                                                </Link>
                                                {listing.ward !== null && (
                                                    <span className="text-label text-faint">
                                                        {" "}
                                                        · {listing.ward}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <p className="mt-4">
                                    <Link
                                        href="/portal/claim"
                                        className="text-body text-gold underline underline-offset-4"
                                    >
                                        {listings.length === 0
                                            ? "Find your business"
                                            : "Claim another"}
                                    </Link>
                                </p>
                            </section>

                            {openClaims.length > 0 && (
                                <section className="rounded-sm border border-rule p-5">
                                    <h2 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                        Claims in progress
                                    </h2>
                                    <ul className="mt-3 flex flex-col gap-2.5">
                                        {openClaims.map((claim) => (
                                            <li key={claim.id}>
                                                <Link
                                                    href={`/portal/claim/${String(claim.id)}`}
                                                    className="text-body text-ink underline underline-offset-4 hover:text-gold"
                                                >
                                                    {claim.tradingName}
                                                </Link>
                                                <span className="text-label text-faint">
                                                    {" "}
                                                    ·{" "}
                                                    {claim.statusLabel.toLowerCase()}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}

                            <section className="rounded-sm border border-rule p-5">
                                <h2 className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                    Your code
                                </h2>
                                <p className="mt-2 numeric-mono text-mono text-ink">
                                    {single.code}
                                </p>
                                <p className="mt-2 text-label text-faint">
                                    Quote this on anything you send us. It never
                                    changes.
                                </p>
                            </section>
                        </aside>
                    </div>
                </>
            ) : (
                <>
                    <div className="mt-10 mb-8">
                        <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                            Your account
                        </p>
                        <h1 className="mt-1 font-display text-display-l text-ink">
                            {account.name}
                        </h1>
                        <p className="mt-2 numeric-mono text-mono text-muted">
                            {account.phone}
                        </p>
                    </div>

                    <ul className="flex flex-col gap-3">
                        {parties.map((party) => (
                            <li
                                key={party.id}
                                className="flex flex-wrap items-baseline justify-between gap-3 rounded-sm border border-rule p-4"
                            >
                                <span className="flex flex-col gap-0.5">
                                    <span className="text-body text-ink">
                                        {party.displayName}
                                    </span>
                                    <span className="numeric-mono text-label text-faint">
                                        {party.code}
                                    </span>
                                </span>
                                <span className="text-ui text-muted">
                                    {party.role}
                                </span>
                            </li>
                        ))}
                    </ul>

                    {parties.length === 0 && (
                        <p className="text-body text-muted">
                            This account is not attached to a business yet.
                        </p>
                    )}
                </>
            )}
        </PortalShell>
    );
}
