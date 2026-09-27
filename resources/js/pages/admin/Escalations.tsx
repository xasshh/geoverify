import { Head, Link } from '@inertiajs/react';
import { ConsoleShell } from '@/components/ConsoleShell';
import { StatusPill } from '@/components/StatusPill';
import { captureStatus } from '@/lib/status';

interface Escalation {
    id: number;
    structureId: number;
    structureType: string;
    observedAt: string;
    score: number | null;
    ward: string | null;
    officer: { id: number; name: string; flaggedTotal: number };
    escalatedAt: string | null;
    escalatedBy: string | null;
    reason: string | null;
}

function on(iso: string): string {
    return new Date(iso).toLocaleDateString('en-NG', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function waitingDays(iso: string | null): number | null {
    return iso === null ? null : Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
}

/**
 * Captures a supervisor would not decide alone.
 *
 * Oldest first, and the wait is on every row. The cost of leaving one of these
 * open falls on the officer: it is an unanswered question about their honesty,
 * and sitting on it is worse than answering it either way.
 */
export default function Escalations({ escalations }: { escalations: Escalation[] }) {
    return (
        <ConsoleShell current="escalations">
            <Head title="Escalations" />

            <div className="mx-auto max-w-[1100px] px-6 pb-20">
                <header className="mt-8 flex flex-wrap items-baseline justify-between gap-4 border-b border-rule pb-3">
                    <div>
                        <p className="text-label font-semibold tracking-[0.05em] text-gold uppercase">
                            In house
                        </p>
                        <h1 className="font-display text-display-m text-ink">Escalations</h1>
                    </div>
                    <p className="numeric-mono text-mono text-muted">
                        {escalations.length} waiting
                    </p>
                </header>

                <p className="mt-6 max-w-[68ch] text-ui text-muted">
                    A supervisor raised each of these rather than deciding it themselves. That is
                    the design: a judgement about whether an officer fabricated a capture should
                    not be made by the one person who suspected it. Upholding sends the capture
                    back to the officer; dismissing enters it into the register.
                </p>

                {escalations.length === 0 ? (
                    <p className="rounded-sm bg-sunken mt-8 px-4 py-3 text-ui text-muted">
                        Nothing is escalated. Captures reach this queue only when a supervisor
                        presses Escalate rather than accepting or returning.
                    </p>
                ) : (
                    <ul className="mt-8 flex flex-col gap-3">
                        {escalations.map((item) => {
                            const days = waitingDays(item.escalatedAt);

                            return (
                                <li
                                    key={item.id}
                                    className="rounded-card border border-rule p-4 bg-raised"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-3">
                                        <div>
                                            <h2 className="font-display text-display-s text-ink">
                                                {item.structureType.replace(/_/g, ' ')}
                                            </h2>
                                            <p className="numeric-mono text-label text-faint">
                                                {item.ward ?? 'ward unresolved'} &middot; captured{' '}
                                                {on(item.observedAt)}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {item.score !== null && (
                                                <span className="numeric-mono text-mono text-muted">
                                                    score {item.score}
                                                </span>
                                            )}
                                            <StatusPill tone={captureStatus('flagged').tone} label="Escalated" />
                                        </div>
                                    </div>

                                    {item.reason !== null && (
                                        <blockquote className="rounded-sm bg-amber-soft mt-3 px-3 py-2 text-ui text-ink">
                                            {item.reason}
                                        </blockquote>
                                    )}

                                    <div className="mt-3 flex flex-wrap items-baseline justify-between gap-3">
                                        <p className="text-label text-faint">
                                            Raised by {item.escalatedBy ?? 'unknown'}
                                            {days !== null &&
                                                ` · waiting ${String(days)} ${days === 1 ? 'day' : 'days'}`}
                                            {' · '}
                                            {item.officer.name} has{' '}
                                            {item.officer.flaggedTotal}{' '}
                                            {item.officer.flaggedTotal === 1
                                                ? 'capture'
                                                : 'captures'}{' '}
                                            escalated
                                        </p>

                                        <Link
                                            href={`/admin/escalations/${String(item.id)}`}
                                            className="text-ui text-gold underline underline-offset-2"
                                        >
                                            Look at the evidence
                                        </Link>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </ConsoleShell>
    );
}
