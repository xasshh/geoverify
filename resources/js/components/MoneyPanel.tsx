import { cx } from '@/lib/cx';

export interface MoneyTerms {
    /** Whole naira. Formatted here so no caller can format it differently. */
    feeNaira: number;
    /** What the customer is buying, in their words. */
    buys: string;
    /** The committed window, already worded: "10 working days". */
    within: string;
    /** Orders ahead of this one. Null when not yet known. */
    queueAhead: number | null;
    /** The tier this establishes. */
    establishes: string;
    /** What the officer will actually do. */
    whatHappens: string;
}

interface MoneyPanelProps {
    terms: MoneyTerms;
    /** The action. Its label is repeated in the button by the caller. */
    children?: React.ReactNode;
    className?: string;
}

function naira(amount: number): string {
    return `₦${amount.toLocaleString('en-NG')}`;
}

/**
 * The money screen, as one component.
 *
 * This exists as a single unit rather than as parts a screen assembles, because
 * the parts must never come apart. The fee, the committed window, the queue
 * position, what a negative finding costs and what a missed window refunds are
 * one disclosure, and a screen that renders the fee without the conditions is
 * the failure mode this component makes impossible.
 *
 * Everything is above the action. No accordion, no "see terms", no asterisk,
 * and the unwelcome sentence is set in the same size as the welcome one. A
 * customer surprised by anything after paying is a chargeback, and worse, is
 * right to be annoyed.
 */
export function MoneyPanel({ terms, children, className }: MoneyPanelProps) {
    return (
        <section
            className={cx('flex flex-col gap-6', className)}
            aria-label={`Fee and terms: ${naira(terms.feeNaira)}`}
        >
            <p className="text-body text-ink">{terms.buys}</p>

            <p className="numeric-mono text-display-xl text-ink">{naira(terms.feeNaira)}</p>

            <dl className="flex flex-col rounded-sm bg-sunken px-4 py-1">
                {[
                    ['Within', terms.within],
                    [
                        'Queue',
                        terms.queueAhead === null
                            ? 'not yet known'
                            : terms.queueAhead === 0
                              ? 'next'
                              : `${String(terms.queueAhead)} ahead`,
                    ],
                    ['Establishes', terms.establishes],
                ].map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-baseline justify-between gap-4 border-b border-rule py-2.5 last:border-b-0"
                    >
                        <dt className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                            {label}
                        </dt>
                        <dd className="numeric-mono text-mono text-ink">{value}</dd>
                    </div>
                ))}
            </dl>

            <div className="flex flex-col gap-2">
                <h3 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                    What you get
                </h3>
                <p className="text-body text-ink">{terms.whatHappens}</p>
            </div>

            {/* Set in body, not in a footnote, and above the button. If the
                officer attends and the business is not there, the work was done
                and the fee is charged: a customer who discovers that afterwards
                is a chargeback we deserved. */}
            <div className="flex flex-col gap-2">
                <h3 className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                    What you should know
                </h3>
                <p className="text-body text-ink">
                    If the officer attends and your business is not at this address, the work is
                    done and the fee is charged. You get the report either way.
                </p>
                <p className="text-body text-ink">
                    If we do not attend within {terms.within}, you are refunded in full,
                    automatically. You do not have to ask.
                </p>
                <p className="text-body text-muted">
                    Your money is held until the report is delivered.
                </p>
            </div>

            {children}
        </section>
    );
}
