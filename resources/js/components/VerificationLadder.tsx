import { cx } from '@/lib/cx';
import {
    STATE_COLOR,
    STATE_GLYPH,
    TIER_LABEL,
    TIER_MEANING,
    TIERS,
    type Rung,
} from '@/lib/tiers';

interface LadderProps {
    rungs: Rung[];
    /**
     * compact: a dashboard card. full: the listing page, with the meaning of
     * each rung. print: the report cover, no interaction, no hover.
     */
    density?: 'compact' | 'full' | 'print';
    className?: string;
}

/**
 * Said in words, because colour and shape are never the only carriers.
 *
 * Compact says less. A dashboard card holds the state in a single line beside
 * the tier name, and a phrase long enough to wrap breaks the column that makes
 * five rungs readable as a set. The full density has the room to say it all.
 */
function stateWords(rung: Rung, density: LadderProps['density']): string {
    if (rung.state === 'not_established') {
        return 'not established';
    }

    if (rung.state === 'pending') {
        const ahead =
            rung.queueAhead === null || rung.queueAhead === undefined
                ? null
                : `${String(rung.queueAhead)} ahead`;

        if (density === 'compact') {
            return ahead ?? 'assigned';
        }

        return ahead === null ? 'officer assigned' : `officer assigned, ${ahead}`;
    }

    const when = rung.establishedOn ?? '';

    return rung.elapsed === null || rung.elapsed === undefined ? when : `${when} · ${rung.elapsed}`;
}


/**
 * The verification ladder.
 *
 * The product's signature element, and the thing it is remembered by. Three
 * rules hold everywhere it appears:
 *
 *  1. All five rungs, always. Hiding the unclimbed ones would let a business
 *     that has only told us it exists look finished. Showing what is not
 *     established is the ladder's job, not a side effect of it.
 *  2. Every rung carries a date or the words "not established". A tier without
 *     its date is a claim about the present that nobody checked.
 *  3. Decay is stated, never warned about. "March 2024, over 3 years" is a fact
 *     the reader can act on. A red banner would be us nagging.
 *
 * It is drawn from the record rather than passed a summary, so it can never say
 * something the register does not.
 */
export function VerificationLadder({ rungs, density = 'full', className }: LadderProps) {
    const byTier = new Map(rungs.map((rung) => [rung.tier, rung]));

    return (
        <ul
            className={cx('flex flex-col', density === 'compact' ? 'gap-1.5' : 'gap-0', className)}
            aria-label="Verification tiers"
        >
            {TIERS.map((tier) => {
                const rung = byTier.get(tier) ?? { tier, state: 'not_established' as const };
                const words = stateWords(rung, density);

                return (
                    <li
                        key={tier}
                        className={cx(
                            'flex items-baseline gap-3',
                            density === 'compact'
                                ? ''
                                : 'border-b border-rule py-3 last:border-b-0',
                        )}
                    >
                        <span
                            aria-hidden="true"
                            className={cx(
                                'w-4 shrink-0 text-center',
                                STATE_COLOR[rung.state],
                                density === 'compact' ? 'text-ui' : 'text-body',
                            )}
                        >
                            {STATE_GLYPH[rung.state]}
                        </span>

                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="flex flex-wrap items-baseline justify-between gap-x-3">
                                <span
                                    className={cx(
                                        'text-ink',
                                        density === 'compact' ? 'text-ui' : 'text-body',
                                        rung.state === 'not_established' && 'text-muted',
                                    )}
                                >
                                    {TIER_LABEL[tier]}
                                </span>

                                <span
                                    className={cx(
                                        'numeric-mono text-label',
                                        rung.state === 'not_established'
                                            ? 'text-faint'
                                            : STATE_COLOR[rung.state],
                                    )}
                                >
                                    {words}
                                </span>
                            </span>

                            {density === 'full' && (
                                <span className="text-label text-faint">{TIER_MEANING[tier]}</span>
                            )}
                        </span>

                        {/* Read aloud as one sentence rather than as a glyph and
                            two fragments. */}
                        <span className="sr-only">
                            {TIER_LABEL[tier]}: {words}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}
