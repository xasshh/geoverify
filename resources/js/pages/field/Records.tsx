import { Link } from '@inertiajs/react';
import { FieldShell } from '@/components/FieldShell';
import { cx } from '@/lib/cx';
import { ago, clock, type OfficerDay } from '@/lib/fieldDay';

interface Returned {
    id: number;
    structureType: string;
    reason: string | null;
    returnedAt: string;
    assignmentId: number;
}

const QA: Record<string, { label: string; className: string }> = {
    submitted: { label: 'Awaiting QA', className: 'bg-held-soft text-held-ink' },
    accepted: { label: 'Accepted', className: 'bg-green-soft text-green' },
    rejected: { label: 'Returned', className: 'bg-alert-soft text-alert-ink' },
    flagged: { label: 'Escalated', className: 'bg-amber-soft text-amber-ink' },
};

/** My records: what came back to fix first, then everything captured today. */
export default function Records({ day, returned }: { day: OfficerDay; returned: Returned[] }) {
    return (
        <FieldShell day={day} current="records" title="My records">
            <section aria-labelledby="to-fix">
                <h2 id="to-fix" className="font-display text-display-s text-ink">
                    Returned to fix · {returned.length}
                </h2>
                {returned.length === 0 ? (
                    <p className="mt-3 rounded-card border border-rule bg-raised px-6 py-6 text-ui text-muted max-w-none">Nothing has been sent back.</p>
                ) : (
                    <ul className="mt-3 flex list-none flex-col gap-3 p-0">
                        {returned.map((r) => (
                            <li key={r.id} className="rounded-card border border-alert/30 bg-alert-soft px-4 py-4">
                                <p className="flex justify-between gap-3 text-label font-extrabold tracking-[0.05em] text-alert-ink uppercase">
                                    Record returned <span className="font-semibold tracking-normal normal-case">{ago(r.returnedAt)}</span>
                                </p>
                                <p className="mt-1 text-body font-bold text-ink capitalize">{r.structureType.replace('_', ' ')}</p>
                                {r.reason !== null && <p className="mt-1 text-ui text-ink">{r.reason}</p>}
                                <Link href={`/field/assignments/${String(r.assignmentId)}/capture`} className="mt-3 inline-flex min-h-[44px] items-center rounded-sm bg-alert px-4 text-ui font-extrabold text-on-accent">
                                    Re-capture now
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="mt-8" aria-labelledby="today">
                <h2 id="today" className="font-display text-display-s text-ink">
                    Captured today · {day.captures.length}
                </h2>
                {day.captures.length === 0 ? (
                    <p className="mt-3 rounded-card border border-rule bg-raised px-6 py-6 text-ui text-muted max-w-none">Nothing captured yet today.</p>
                ) : (
                    <ul className="mt-3 flex list-none flex-col gap-2 p-0">
                        {day.captures.map((c) => {
                            const qa = QA[c.status] ?? { label: c.status, className: 'bg-sunken text-muted' };

                            return (
                                <li key={c.id} className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-rule bg-raised px-4 py-3">
                                    <span className="min-w-0">
                                        <span className="block font-bold text-ink">{c.business ?? 'No business recorded'}</span>
                                        <span className="block text-table text-muted">
                                            <span className="numeric-mono">{c.ref}</span> · <span className="capitalize">{c.type}</span> · {clock(c.at)} · {c.photos} photos
                                            {c.accuracyM !== null && ` · ±${String(Math.round(c.accuracyM))} m`}
                                        </span>
                                    </span>
                                    <span className={cx('rounded-full px-2.5 py-1 text-table font-bold', qa.className)}>{qa.label}</span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>
        </FieldShell>
    );
}
