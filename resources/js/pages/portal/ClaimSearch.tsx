import { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import { PortalShell } from "@/components/PortalShell";
import { TextField, SelectField } from "@/components/Field";
import { Button } from "@/components/Button";
import { StatusPill } from "@/components/StatusPill";
import { TIER_STATEMENT, type Tier } from "@/lib/tiers";

interface Result {
    enterprise_id: number;
    trading_name: string;
    structure_type: string;
    ward: string | null;
    lga: string | null;
    tier: string;
    established_on: string;
    is_claimed: boolean;
    can_prove_by_phone: boolean;
    metres_away: number | null;
}

interface Props {
    term: string;
    results: Result[];
    searched: boolean;
    party: { code: string | null; displayName: string | null };
    relationships: { value: string; label: string }[];
}

/**
 * Finding your own business in a register you have never seen.
 *
 * Results are a list with rules between them, not a grid of cards. A card grid
 * says "browse these"; this person is looking for exactly one thing they
 * already know the name of, and every card border is furniture between them and
 * it. The same reason there is no filter panel: filters help when you do not
 * know what you want.
 *
 * What each row shows is fixed by the server, not chosen here. See
 * SearchRegister for why the projection is thin.
 */
export default function ClaimSearch({
    term,
    results,
    searched,
    party,
    relationships,
}: Props) {
    const [open, setOpen] = useState<number | null>(null);

    const search = useForm({ q: term });

    return (
        <PortalShell accountName={party.displayName} width="page">
            <Head title="Find your business" />

            <div className="mt-10 mb-8">
                <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                    Claim a business
                </p>
                <h1 className="mt-1 font-display text-display-l text-ink">
                    Find your business in the register
                </h1>
                <p className="mt-3 max-w-prose text-body text-muted">
                    Officers have recorded businesses across the covered wards.
                    Search for yours by the name on the signage. If it is not
                    here, you can add it instead.
                </p>
            </div>

            <form
                className="flex flex-wrap items-end gap-3 border-y border-rule py-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.get(
                        "/portal/claim",
                        { q: search.data.q },
                        { preserveState: true },
                    );
                }}
            >
                <div className="min-w-60 flex-1">
                    <TextField
                        label="Business name"
                        value={search.data.q}
                        onChange={(e) => {
                            search.setData("q", e.target.value);
                        }}
                        placeholder="Mama Ngozi Provisions"
                        autoFocus
                    />
                </div>
                <Button type="submit" variant="primary">
                    Search
                </Button>
            </form>

            {searched && results.length === 0 && (
                <div className="mt-8 max-w-prose">
                    <h2 className="font-display text-display-s text-ink">
                        Nothing in the register matches that
                    </h2>
                    <p className="mt-2 text-body text-muted">
                        Officers have not covered every ward yet. Try the name
                        exactly as it appears on your signage, or add the
                        business yourself.
                    </p>
                </div>
            )}

            {results.length > 0 && (
                <ul className="mt-8 flex flex-col">
                    {results.map((result) => (
                        <li
                            key={result.enterprise_id}
                            className="border-b border-rule py-5"
                        >
                            <div className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                                <div className="min-w-0">
                                    <h2 className="font-display text-display-s text-ink">
                                        {result.trading_name}
                                    </h2>
                                    <p className="mt-1 text-ui text-muted">
                                        {[result.ward, result.lga]
                                            .filter(Boolean)
                                            .join(", ")}
                                        {" · "}
                                        {result.structure_type.replace(
                                            /_/g,
                                            " ",
                                        )}
                                        {result.metres_away !== null &&
                                            ` · ${String(result.metres_away)} m away`}
                                    </p>
                                    {/* The tier never appears without its date. */}
                                    <p className="mt-1 numeric-mono text-label text-faint">
                                        {TIER_STATEMENT[result.tier as Tier]} ·{" "}
                                        {result.established_on}
                                    </p>
                                    {/* Says that we hold a number, never what it
                                        is. It tells an owner which of several
                                        similar rows is likely theirs, and which
                                        route they are about to be sent down. */}
                                    {result.can_prove_by_phone &&
                                        !result.is_claimed && (
                                            <p
                                                className="mt-1 text-label text-green"
                                                data-testid="phone-available"
                                            >
                                                A phone number was recorded here
                                            </p>
                                        )}
                                </div>

                                {result.is_claimed ? (
                                    <div className="flex flex-col items-start gap-1.5">
                                        <StatusPill
                                            tone="held"
                                            label="Already claimed"
                                            emphasis="filled"
                                        />
                                        <button
                                            type="button"
                                            className="text-label text-muted underline underline-offset-2 hover:text-ink"
                                            onClick={() => {
                                                setOpen(
                                                    open ===
                                                        result.enterprise_id
                                                        ? null
                                                        : result.enterprise_id,
                                                );
                                            }}
                                        >
                                            This is mine
                                        </button>
                                    </div>
                                ) : (
                                    <Button
                                        variant="secondary"
                                        onClick={() => {
                                            setOpen(
                                                open === result.enterprise_id
                                                    ? null
                                                    : result.enterprise_id,
                                            );
                                        }}
                                    >
                                        {open === result.enterprise_id
                                            ? "Cancel"
                                            : "This is mine"}
                                    </Button>
                                )}
                            </div>

                            {open === result.enterprise_id && (
                                <ClaimForm
                                    result={result}
                                    relationships={relationships}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </PortalShell>
    );
}

/**
 * The assertion, and what happens next, said before it is made.
 *
 * A person about to claim a business that somebody else already holds is told
 * so here rather than on the next screen. Being told after submitting that you
 * have opened a dispute reads as a trap, and the resolution path is exactly
 * what makes it not one.
 */
function ClaimForm({
    result,
    relationships,
}: {
    result: Result;
    relationships: { value: string; label: string }[];
}) {
    const form = useForm({
        enterprise_id: result.enterprise_id,
        relationship: relationships[0]?.value ?? "owner",
        lat: null as number | null,
        lng: null as number | null,
    });

    return (
        <form
            className="mt-5 flex max-w-prose flex-col gap-4 border-l-2 border-gold bg-raised py-4 pr-4 pl-5"
            onSubmit={(e) => {
                e.preventDefault();
                form.post("/portal/claim");
            }}
        >
            <SelectField
                label="How are you connected to this business?"
                value={form.data.relationship}
                onChange={(e) => {
                    form.setData("relationship", e.target.value);
                }}
                {...(form.errors.relationship !== undefined && {
                    error: form.errors.relationship,
                })}
            >
                {relationships.map((r) => (
                    <option key={r.value} value={r.value}>
                        {r.label}
                    </option>
                ))}
            </SelectField>

            <div className="text-body text-ink">
                {result.is_claimed ? (
                    <>
                        <p>
                            Someone already manages this listing. Claiming it
                            opens a dispute: a reviewer reads both sides and
                            decides, and you will be told the outcome.
                        </p>
                        <p className="mt-2 text-muted">
                            They keep managing it until then. That is
                            deliberate, so nobody can take a listing offline
                            just by claiming it.
                        </p>
                    </>
                ) : result.can_prove_by_phone ? (
                    <p>
                        The officer recorded a phone number for this business.
                        If you have that phone, you can confirm in a minute.
                    </p>
                ) : (
                    <p>
                        No phone number was recorded here, so a reviewer will
                        look at your claim. You can add documents on the next
                        screen.
                    </p>
                )}
            </div>

            {form.errors.enterprise_id !== undefined && (
                <p className="text-ui text-alert">
                    {form.errors.enterprise_id}
                </p>
            )}

            <div>
                <Button type="submit" variant="primary" busy={form.processing}>
                    {result.is_claimed
                        ? "Claim and open a dispute"
                        : "Claim this business"}
                </Button>
            </div>
        </form>
    );
}
