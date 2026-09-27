import { motion, useReducedMotion } from 'motion/react';

export interface TrackerStep {
    label: string;
    at: string | null;
    reached?: boolean;
}

/**
 * Where a paid visit has got to.
 *
 * Four beats, because that is what the order actually goes through: ordered,
 * paid, an officer assigned, the report accepted. Each carries the date it
 * happened or nothing at all, and a step with no date is drawn as not reached
 * rather than as pending-looking-like-done.
 *
 * The bar between two reached steps fills once, on arrival, and the current
 * step's marker breathes. That is the whole of the motion: a customer who
 * refreshes this page because they are anxious about a visit should see that
 * something is moving, and should not see a dashboard that moves for its own
 * sake. Both are dropped entirely when the viewer has asked for less.
 */
export function OrderTracker({ steps }: { steps: TrackerStep[] }) {
    const still = useReducedMotion();

    const reached = (step: TrackerStep) => step.reached ?? step.at !== null;
    const currentIndex = steps.findIndex((step) => !reached(step));
    const current = currentIndex === -1 ? steps.length - 1 : currentIndex - 1;

    return (
        <ol className="flex list-none items-start gap-0 p-0">
            {steps.map((step, index) => {
                const done = reached(step);
                const isCurrent = index === current;
                const last = index === steps.length - 1;

                return (
                    <li
                        key={step.label}
                        className={`flex flex-col items-start gap-2 ${last ? 'shrink-0' : 'flex-1'}`}
                    >
                        <div className="flex w-full items-center">
                            <motion.span
                                aria-hidden="true"
                                className={
                                    done
                                        ? isCurrent
                                            ? 'size-3 shrink-0 bg-amber'
                                            : 'size-3 shrink-0 rounded-full bg-green'
                                        : 'size-3 shrink-0 rounded-full border-[1.5px] border-rule-strong'
                                }
                                {...(isCurrent && still !== true
                                    ? {
                                          animate: { opacity: [1, 0.45, 1] },
                                          transition: {
                                              duration: 2.4,
                                              repeat: Infinity,
                                              ease: 'easeInOut',
                                          },
                                      }
                                    : {})}
                            />
                            {!last && (
                                <span className="h-0.5 flex-grow bg-sunken">
                                    <motion.span
                                        aria-hidden="true"
                                        className="block h-0.5 bg-green"
                                        // The bar belongs to the step it arrives
                                        // at, not the one it leaves. Filling it
                                        // because this step is done draws a line
                                        // into a beat that has not happened, and
                                        // a customer reads that as the officer
                                        // being on the way when nobody has paid.
                                        initial={{ scaleX: 0 }}
                                        animate={{
                                            scaleX: reached(steps[index + 1] as TrackerStep) ? 1 : 0,
                                        }}
                                        transition={{
                                            duration: still === true ? 0 : 0.5,
                                            delay: still === true ? 0 : 0.15 * index,
                                            ease: 'easeOut',
                                        }}
                                        style={{ transformOrigin: 'left' }}
                                    />
                                </span>
                            )}
                        </div>

                        <span
                            className={`text-table font-medium ${
                                isCurrent ? 'text-amber-ink' : done ? 'text-ink' : 'text-muted'
                            }`}
                        >
                            {step.label}
                        </span>
                        <span className="numeric-mono text-table text-faint">
                            {step.at === null ? (done ? 'done' : 'not yet') : shortDate(step.at)}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

function shortDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short' });
}
