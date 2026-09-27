import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { InvestorShell } from '@/components/InvestorShell';
import { cx } from '@/lib/cx';

interface Tier {
    key: string;
    name: string;
    buys: string;
    happens: string;
    feeNaira: number | null;
}

interface Props {
    opportunity: { id: number; name: string; place: string; score: number; lastVerified: string | null };
    tiers: Tier[];
    tier: string;
    urgency: 'standard' | 'express';
    zone: string;
    quote: { feeNaira: number; within: string };
    expressAvailable: boolean;
}

function naira(n: number): string {
    return `₦${n.toLocaleString('en-NG')}`;
}

const NEXT = [
    ['You pay', 'The fee is held until the report is accepted'],
    ['An agent is assigned', 'The nearest available field agent'],
    ['The agent visits', 'GPS fix, photographs and a written report'],
    ['You get the certificate', 'Once a supervisor accepts the report'],
] as const;

/**
 * Commissioning a visit: which visit, how fast, what it costs and what happens
 * next. Laid out as the guide's checkout, because it is one.
 */
export default function Commission({ opportunity, tiers, tier, urgency, zone, quote, expressAvailable }: Props) {
    const form = useForm({ tier, urgency });
    const chosen = tiers.find((t) => t.key === tier);

    const requote = (next: { tier?: string; urgency?: string }) => {
        router.get(
            `/invest/opportunities/${String(opportunity.id)}/commission`,
            { tier, urgency, ...next },
            { preserveScroll: true, preserveState: false },
        );
    };

    return (
        <InvestorShell
            current="opportunities"
            crumbs={
                <>
                    <Link href={`/invest/opportunities/${String(opportunity.id)}`} className="hover:text-ink">
                        {opportunity.name}
                    </Link>{' '}
                    / Commission a verification
                </>
            }
            title="Commission a verification"
            subtitle={`${opportunity.name} · ${opportunity.place}`}
        >
            <Head title="Commission a verification" />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_440px] xl:items-start">
                <div className="flex flex-col gap-4">
                    <h2 className="font-display text-display-s text-ink">Choose the visit</h2>
                    {tiers.map((t) => {
                        const active = t.key === tier;

                        return (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => {
                                    requote({ tier: t.key });
                                }}
                                aria-pressed={active}
                                className={cx(
                                    'flex gap-4 rounded-card border bg-raised p-6 text-left shadow-card transition-colors',
                                    active ? 'border-2 border-gold' : 'border-rule hover:border-rule-strong',
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className={cx(
                                        'mt-1 flex size-5 shrink-0 items-center justify-center rounded-full border-2',
                                        active ? 'border-gold' : 'border-rule-strong',
                                    )}
                                >
                                    {active && <span className="size-2.5 rounded-full bg-gold" />}
                                </span>
                                <span className="flex flex-1 flex-col">
                                    <span className="flex flex-wrap items-baseline justify-between gap-3">
                                        <span className="text-body font-extrabold text-ink">{t.name}</span>
                                        <span className="text-body font-extrabold text-ink">
                                            {t.feeNaira === null ? 'Not offered here' : naira(t.feeNaira)}
                                        </span>
                                    </span>
                                    <span className="mt-1 text-ui text-muted">{t.buys}</span>
                                    {active && <span className="mt-2 text-table text-muted">{t.happens}</span>}
                                </span>
                            </button>
                        );
                    })}

                    <h2 className="mt-4 font-display text-display-s text-ink">How soon</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {(['standard', 'express'] as const).map((u) => {
                            const active = u === urgency;
                            const disabled = u === 'express' && !expressAvailable;

                            return (
                                <button
                                    key={u}
                                    type="button"
                                    disabled={disabled}
                                    onClick={() => {
                                        requote({ urgency: u });
                                    }}
                                    aria-pressed={active}
                                    className={cx(
                                        'flex min-h-[64px] items-center gap-3 rounded-card border bg-raised px-5 text-left text-ui font-bold text-ink disabled:cursor-not-allowed disabled:text-faint',
                                        active ? 'border-2 border-gold' : 'border-rule',
                                    )}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cx(
                                            'flex size-5 items-center justify-center rounded-full border-2',
                                            active ? 'border-gold' : 'border-rule-strong',
                                        )}
                                    >
                                        {active && <span className="size-2.5 rounded-full bg-gold" />}
                                    </span>
                                    {u === 'standard' ? 'Standard' : disabled ? 'Express (not offered in this zone)' : 'Express'}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="flex flex-col gap-6">
                    <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <h2 className="font-display text-display-s text-ink">Order summary</h2>
                        <dl className="mt-4 flex flex-col gap-2.5 text-ui">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted">{chosen?.name}</dt>
                                <dd className="font-semibold text-ink">{naira(quote.feeNaira)}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted">Service zone</dt>
                                <dd className="font-semibold text-ink">{zone}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted">Report within</dt>
                                <dd className="font-semibold text-ink">{quote.within}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="font-bold text-gold">Refund if we are late</dt>
                                <dd className="font-bold text-gold">Included</dd>
                            </div>
                        </dl>
                        <div className="mt-4 flex items-baseline justify-between border-t border-dashed border-rule-strong pt-4">
                            <span className="font-display text-display-s text-ink">Total</span>
                            <span className="font-display text-display-m text-ink">{naira(quote.feeNaira)}</span>
                        </div>
                        {form.errors.tier !== undefined && (
                            <p className="mt-4 rounded-sm bg-alert-soft px-4 py-3 text-ui text-alert-ink">{form.errors.tier}</p>
                        )}
                        <Button
                            variant="primary"
                            size="field-primary"
                            fullWidth
                            busy={form.processing}
                            onClick={() => {
                                form.post(`/invest/opportunities/${String(opportunity.id)}/commission`);
                            }}
                        >
                            Commission and pay
                        </Button>
                        <p className="mt-3 text-center text-table text-muted">
                            The fee is held until the report is accepted, and refunded in full if we miss the date.
                        </p>
                    </section>

                    <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <h2 className="font-display text-display-s text-ink">What happens next</h2>
                        <ol className="mt-4 flex list-none flex-col gap-4">
                            {NEXT.map(([title, detail], index) => (
                                <li key={title} className="flex gap-3.5">
                                    <span
                                        className={cx(
                                            'flex size-8 shrink-0 items-center justify-center rounded-full text-table font-extrabold',
                                            index === 0 ? 'bg-gold text-on-accent' : 'bg-sunken text-muted',
                                        )}
                                    >
                                        {index + 1}
                                    </span>
                                    <span className="flex flex-col">
                                        <span className="text-ui font-extrabold text-ink">{title}</span>
                                        <span className="text-table text-muted">{detail}</span>
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>
            </div>
        </InvestorShell>
    );
}
