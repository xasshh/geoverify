import { cx } from '@/lib/cx';

export interface AnomalyFlag {
    id: string;
    /** hard flags stop the eye. deductions are proportional and quieter. */
    severity: 'hard' | 'deduction';
    message: string;
}

interface ConfidenceMeterProps {
    score: number;
    flags?: readonly AnomalyFlag[];
}

/**
 * Confidence, and what produced it.
 *
 * The system surfaces suspicion, it does not adjudicate it. Nothing here rejects
 * anything: the score routes a capture to a supervisor with the evidence laid out,
 * because a false accusation against a field officer is worse than a slow queue.
 * The score is therefore never shown without its contributing signals.
 */
export function ConfidenceMeter({ score, flags = [] }: ConfidenceMeterProps) {
    const tone = score >= 75 ? 'green' : score >= 45 ? 'amber' : 'alert';
    const bar = { green: 'bg-green', amber: 'bg-amber', alert: 'bg-alert' }[tone];
    const text = { green: 'text-green', amber: 'text-amber', alert: 'text-alert' }[tone];

    // Reads as a word as well as a hue and a number, so it survives greyscale.
    const verdict = score >= 75 ? 'Consistent' : score >= 45 ? 'Needs review' : 'Contested';

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                    Confidence
                </span>
                <span className="flex items-baseline gap-2">
                    <span className={cx('numeric-mono text-display-s font-semibold', text)}>
                        {score}
                    </span>
                    <span className={cx('text-ui font-semibold', text)}>{verdict}</span>
                </span>
            </div>

            <div
                className="h-2 w-full overflow-hidden rounded-full bg-sunken"
                role="meter"
                aria-valuenow={score}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`Capture confidence ${String(score)} of 100: ${verdict}`}
            >
                <div className={cx('h-full rounded-full', bar)} style={{ width: `${String(score)}%` }} />
            </div>

            {flags.length > 0 && (
                <ul className="mt-0.5 flex flex-col gap-1">
                    {flags.map((flag) => (
                        <li
                            key={flag.id}
                            className={cx(
                                'flex items-start gap-2 text-ui',
                                flag.severity === 'hard' ? 'text-alert' : 'text-muted',
                            )}
                        >
                            <svg
                                width="10"
                                height="9"
                                viewBox="0 0 11 10"
                                aria-hidden="true"
                                className="mt-1 shrink-0"
                            >
                                {flag.severity === 'hard' ? (
                                    <path d="M5.5 0 11 5 5.5 10 0 5z" fill="currentColor" />
                                ) : (
                                    <path d="M5.5 0 11 10H0z" fill="currentColor" />
                                )}
                            </svg>
                            <span>
                                <span className="sr-only">
                                    {flag.severity === 'hard' ? 'Hard flag: ' : 'Deduction: '}
                                </span>
                                {flag.message}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
