import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Button } from "@/components/Button";
import { MoneyPanel, type MoneyTerms } from "@/components/MoneyPanel";
import { PortalShell } from "@/components/PortalShell";

interface Props {
    business: { id: number; tradingName: string; ward: string | null };
    tier: { key: string; name: string; buys: string; happens: string };
    urgency: string;
    zone: { code: string; label: string };
    terms: MoneyTerms;
    expressAvailable: boolean;
    errors: Record<string, string>;
}

/**
 * The money screen.
 *
 * One decision on the page, and everything needed to make it above the button.
 * The urgency toggle reloads from the server rather than doing arithmetic here:
 * a price the browser worked out is a price nobody can be held to, and the two
 * would eventually disagree.
 */
export default function Order({
    business,
    tier,
    urgency,
    zone,
    terms,
    expressAvailable,
    errors,
}: Props) {
    // Posted from the props rather than from form state. Switching urgency
    // reloads this same component, which keeps its own state across the visit:
    // a useForm initialised on first render would still be holding the urgency
    // the customer started on, and would buy the wrong thing.
    const [placing, setPlacing] = useState(false);

    return (
        <PortalShell
            accountName={business.tradingName}
            kicker={business.tradingName}
            title={tier.name}
            width="form"
        >
            <Head title={tier.name} />

            {errors.tier !== undefined && (
                <p className="rounded-sm bg-alert-soft mb-6 px-4 py-2.5 text-ui text-alert-ink">
                    {errors.tier}
                </p>
            )}

            {expressAvailable && (
                <div className="mb-8 flex flex-col gap-2">
                    <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                        How soon
                    </p>
                    <div className="flex gap-2">
                        {[
                            { value: "standard", label: "Standard" },
                            { value: "express", label: "Express" },
                        ].map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                aria-pressed={urgency === option.value}
                                onClick={() => {
                                    router.get(
                                        `/portal/businesses/${String(business.id)}/verify/${tier.key}`,
                                        { urgency: option.value },
                                        { preserveScroll: true },
                                    );
                                }}
                                className={
                                    urgency === option.value
                                        ? "flex-1 rounded-sm border border-ink bg-ink px-4 py-2.5 text-ui text-surface"
                                        : "flex-1 rounded-sm border border-rule-strong px-4 py-2.5 text-ui text-muted hover:border-ink hover:text-ink"
                                }
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <MoneyPanel terms={terms}>
                {/* The zone, named and explained. A customer who is charged
                    more for being further out is owed the reason on the same
                    screen as the number, not in a help article. */}
                <p className="text-ui text-muted">
                    {business.ward === null
                        ? zone.label
                        : `${business.ward} is ${zone.label.toLowerCase()}.`}
                </p>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        setPlacing(true);
                        router.post(
                            `/portal/businesses/${String(business.id)}/verify`,
                            { tier: tier.key, urgency },
                            {
                                onFinish: () => {
                                    setPlacing(false);
                                },
                            },
                        );
                    }}
                >
                    <Button
                        type="submit"
                        variant="primary"
                        size="field"
                        fullWidth
                        busy={placing}
                    >
                        Continue to payment
                    </Button>
                </form>

                <p className="text-ui text-muted">
                    Nothing is charged until you complete payment on the next
                    screen.
                </p>
            </MoneyPanel>
        </PortalShell>
    );
}
