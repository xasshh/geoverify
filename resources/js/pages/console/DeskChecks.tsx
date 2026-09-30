import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { cx } from '@/lib/cx';
import { ago } from '@/lib/fieldDay';
import { companyType, registration } from '@/lib/enumerate';

interface Check {
    id: number;
    kind: 'cac' | 'tin';
    provider: string;
    outcome: 'matched' | 'mismatched' | 'not_found' | 'unavailable';
    note: string | null;
    facts: Record<string, unknown> | null;
    checkedAt: string;
}

interface Props {
    queue: { reference: string; business: string; rcNumber: string; tier: number; tierLabel: string; status: string; paidAt: string }[];
    selected: {
        reference: string;
        business: string;
        rcNumber: string;
        companyType: string;
        tier: number;
        tierLabel: string;
        monitoringDays: number | null;
        status: string;
        statusLabel: string;
        paidAt: string;
        requester: string | null;
        outcome: 'passed' | 'failed' | null;
        reason: string | null;
        decidedBy: string | null;
        decidedAt: string | null;
        checks: Check[];
    } | null;
}

const OUTCOME: Record<Check['outcome'], { label: string; className: string }> = {
    matched: { label: 'Matches', className: 'bg-green-soft text-green' },
    mismatched: { label: 'Does not match', className: 'bg-alert-soft text-alert-ink' },
    not_found: { label: 'Not found', className: 'bg-alert-soft text-alert-ink' },
    unavailable: { label: 'No answer', className: 'bg-amber-soft text-amber-ink' },
};

const fact = (v: unknown): string => (typeof v === 'string' && v !== '' ? v : '·');

/** One lookup, with everything the register said, for the supervisor to weigh. */
function CheckCard({ check }: { check: Check }) {
    const f = check.facts ?? {};
    const directors = Array.isArray(f.directors) ? (f.directors as { name: string; role: string }[]) : [];

    return (
        <div className="rounded-card border border-rule bg-raised px-4 py-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-ui font-extrabold text-ink">{check.kind === 'cac' ? 'CAC' : 'FIRS TIN'}</p>
                <span className={cx('rounded-full px-2.5 py-1 text-table font-bold', OUTCOME[check.outcome].className)}>{OUTCOME[check.outcome].label}</span>
            </div>
            <p className="mt-1 text-[0.75rem] text-muted">
                {check.provider} · {ago(check.checkedAt)}
            </p>
            {check.note !== null && <p className="mt-2 text-table font-semibold text-ink">{check.note}</p>}
            {check.facts !== null && (
                <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-table">
                    {check.kind === 'cac' ? (
                        <>
                            <dt className="text-muted">Name</dt><dd className="font-bold text-ink">{fact(f.name)}</dd>
                            <dt className="text-muted">Status</dt><dd className="font-bold text-ink">{fact(f.status)}</dd>
                            <dt className="text-muted">Incorporated</dt><dd className="text-ink">{fact(f.incorporatedOn)}</dd>
                            <dt className="text-muted">Address</dt><dd className="text-ink">{fact(f.address)}</dd>
                            <dt className="text-muted">Directors</dt>
                            <dd className="text-ink">{directors.length === 0 ? '·' : directors.map((d) => `${d.name} (${d.role})`).join(', ')}</dd>
                        </>
                    ) : (
                        <>
                            <dt className="text-muted">TIN</dt><dd className="font-mono font-bold text-ink">{fact(f.tin)}</dd>
                            <dt className="text-muted">Name on TIN</dt><dd className="font-bold text-ink">{fact(f.nameOnTin)}</dd>
                        </>
                    )}
                </dl>
            )}
        </div>
    );
}

/**
 * Enumerate desk checks: what CAC and FIRS said about a business somebody paid
 * to have checked, and the supervisor's reading of it. The requester reads the
 * reason for a failure on their report, so it is written for them.
 */
export default function DeskChecks({ queue, selected }: Props) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState<'pass' | 'fail' | 'rerun' | null>(null);

    const decide = (passed: boolean) => {
        if (selected === null) {
            return;
        }

        setBusy(passed ? 'pass' : 'fail');
        router.post(`/console/desk-checks/${selected.reference}`, { passed, reason: reason.trim() === '' ? null : reason }, {
            preserveScroll: true,
            onFinish: () => { setBusy(null); },
            onSuccess: () => { setReason(''); },
        });
    };

    const latest = (kind: Check['kind']) => selected?.checks.find((c) => c.kind === kind) ?? null;
    const suggested = [latest('cac')?.note, latest('tin')?.note].filter((n): n is string => typeof n === 'string').join(' ');

    return (
        <ConsoleShell current="deskChecks">
            <Head title="Desk checks" />
            <div className="mx-auto max-w-[1280px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <h1 className="font-display text-display-l text-ink">Desk checks</h1>
                    <p className="mt-1 text-ui text-muted">Enumerate requests, oldest first. Read what the registers said, then pass or fail with a reason the requester will read.</p>
                </header>

                {page.props.flash.status !== null && <p className="mt-5 max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{page.props.flash.status}</p>}

                <div className="mt-6 grid gap-5 lg:grid-cols-[340px_minmax(0,1fr)] lg:items-start">
                    <ul className="overflow-hidden rounded-card border border-rule bg-raised">
                        {queue.length === 0 && <li className="px-5 py-10 text-center text-ui text-muted">Nothing is waiting.</li>}
                        {queue.map((q) => (
                            <li key={q.reference} className="border-b border-rule last:border-b-0">
                                <Link
                                    href={`/console/desk-checks?request=${q.reference}`}
                                    preserveScroll
                                    className={cx('block px-5 py-3.5 hover:bg-sunken', selected?.reference === q.reference && 'border-l-4 border-gold bg-gold-soft/40')}
                                >
                                    <p className="font-mono text-[0.75rem] text-muted">{q.reference}</p>
                                    <p className="truncate text-ui font-bold text-ink">{q.business}</p>
                                    <p className="text-table text-muted">
                                        {q.tierLabel} · {q.status === 'paid' ? 'lookups running' : 'ready to read'} · paid {ago(q.paidAt)}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>

                    {selected === null ? (
                        <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">Choose a request from the queue.</p>
                    ) : (
                        <section className="rounded-card border border-rule bg-surface">
                            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-rule bg-raised px-5 py-4">
                                <div>
                                    <p className="font-mono text-table text-muted">{selected.reference}</p>
                                    <h2 className="text-[1.25rem] font-extrabold text-ink">{selected.business}</h2>
                                    <p className="text-table text-muted">
                                        <span className="font-mono">{registration(selected.rcNumber, selected.companyType)}</span> · {companyType(selected.companyType)} · {selected.tierLabel}
                                        {selected.monitoringDays !== null && `, ${String(selected.monitoringDays)} days`} · for {selected.requester ?? 'a requester'}
                                    </p>
                                </div>
                                <Button
                                    size="console"
                                    busy={busy === 'rerun'}
                                    onClick={() => {
                                        setBusy('rerun');
                                        router.post(`/console/desk-checks/${selected.reference}/rerun`, {}, { preserveScroll: true, onFinish: () => { setBusy(null); } });
                                    }}
                                    disabled={selected.outcome !== null}
                                >
                                    Ask the registers again
                                </Button>
                            </div>

                            <div className="grid gap-4 px-5 py-5 md:grid-cols-2">
                                {selected.checks.length === 0 && <p className="text-ui text-muted">The lookups have not come back yet.</p>}
                                {selected.checks.map((c) => <CheckCard key={c.id} check={c} />)}
                            </div>

                            <div className="border-t border-rule bg-raised px-5 py-5">
                                {selected.outcome !== null ? (
                                    <p className="text-ui text-ink">
                                        <span className="font-extrabold">{selected.outcome === 'passed' ? 'Passed' : 'Failed'}</span> by {selected.decidedBy} {ago(selected.decidedAt)}
                                        {selected.reason !== null && `: ${selected.reason}`}
                                    </p>
                                ) : (
                                    <>
                                        <label className="flex flex-col gap-1.5">
                                            <span className="text-ui font-bold text-ink">Reason (needed to fail; the requester reads it)</span>
                                            <textarea
                                                value={reason}
                                                onChange={(e) => { setReason(e.target.value); }}
                                                rows={2}
                                                maxLength={300}
                                                placeholder={suggested === '' ? 'e.g. TIN does not match CAC name' : suggested}
                                                className="rounded-sm border border-rule-strong bg-raised px-3 py-2 text-ui text-ink focus:border-gold focus:outline-none"
                                            />
                                        </label>
                                        {errors.reason !== undefined && <p role="alert" className="mt-2 text-ui font-semibold text-alert-ink">{errors.reason}</p>}
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <Button variant="primary" busy={busy === 'pass'} disabled={selected.status !== 'registry_check'} onClick={() => { decide(true); }}>
                                                {selected.tier === 1 ? 'Pass' : 'Pass and send for a visit'}
                                            </Button>
                                            <Button variant="destructive" busy={busy === 'fail'} disabled={selected.status !== 'registry_check'} onClick={() => { decide(false); }}>
                                                Fail
                                            </Button>
                                            {suggested !== '' && reason === '' && (
                                                <Button variant="quiet" onClick={() => { setReason(suggested); }}>
                                                    Use the registers’ wording
                                                </Button>
                                            )}
                                        </div>
                                        {selected.tier > 1 && <p className="mt-2 text-table text-muted">Failing a Tier {selected.tier} keeps the desk-check fee and returns the rest to the requester’s wallet.</p>}
                                    </>
                                )}
                            </div>
                        </section>
                    )}
                </div>
            </div>
        </ConsoleShell>
    );
}
