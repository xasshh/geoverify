import { Head, Link } from '@inertiajs/react';
import { EnumerateShell } from '@/components/EnumerateShell';
import { TierBadge } from '@/components/EnumerateParts';
import { StatusPill } from '@/components/StatusPill';
import { STATUS_TONE, kobo, type EnumerateFrame, type RequestRow } from '@/lib/enumerate';
import type { BatchRow } from '@/lib/organisation';

interface Props {
    frame: EnumerateFrame;
    batch: BatchRow;
    rows: { line: number; name: string | null; rcNumber: string | null; outcome: 'placed' | 'refused'; reason: string | null; request: RequestRow | null }[];
}

/** One bulk upload: every line of the file and what became of it. */
export default function Batch({ frame, batch, rows }: Props) {
    return (
        <EnumerateShell
            current="requests"
            frame={frame}
            title={`Bulk verification ${batch.reference}`}
            crumbs={<Link href="/enumerate/organisation" className="hover:text-ink">Overview</Link>}
        >
            <Head title={batch.reference} />

            <p className="mb-5 text-ui text-muted">
                {batch.total} lines · {batch.placed} placed at Tier {batch.tier}
                {batch.monitoringDays !== null && ` (${String(batch.monitoringDays)} days)`} for {kobo(batch.totalMinor)} · {batch.refused} could not be checked and cost nothing.
            </p>

            <div className="overflow-x-auto rounded-card border border-rule bg-raised">
                <table className="w-full min-w-[720px] text-left">
                    <thead>
                        <tr className="border-b border-rule bg-sunken/60 text-[0.6875rem] font-extrabold tracking-[0.06em] text-muted uppercase">
                            <th className="px-5 py-3">Line</th>
                            <th className="px-3 py-3">Business</th>
                            <th className="px-3 py-3">RC/BN</th>
                            <th className="px-3 py-3">Outcome</th>
                            <th className="px-5 py-3"><span className="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.line} className="border-b border-rule last:border-b-0">
                                <td className="px-5 py-3 text-table text-muted">{r.line}</td>
                                <td className="px-3 py-3 text-ui font-bold text-ink">{r.name ?? '·'}</td>
                                <td className="px-3 py-3 font-mono text-table text-muted">{r.rcNumber ?? '·'}</td>
                                <td className="px-3 py-3">
                                    {r.request !== null ? (
                                        <span className="flex items-center gap-2">
                                            <TierBadge tier={r.request.tier} label={r.request.tierLabel} />
                                            <StatusPill tone={STATUS_TONE[r.request.status]} label={r.request.statusLabel} size="sm" />
                                        </span>
                                    ) : (
                                        <span className="text-table font-semibold text-alert-ink">Not checked: {r.reason}</span>
                                    )}
                                </td>
                                <td className="px-5 py-3 text-right">
                                    {r.request !== null && (
                                        <Link href={`/enumerate/verifications/${r.request.reference}`} className="text-ui font-extrabold text-gold hover:text-gold-dark">
                                            View →
                                        </Link>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </EnumerateShell>
    );
}
