import { Head, Link, usePage } from '@inertiajs/react';
import { PortalShell } from '@/components/PortalShell';
import { cx } from '@/lib/cx';

interface Job {
    id: number;
    orderId: number;
    label: string;
    status: 'requested' | 'assigned' | 'submitted' | 'approved' | 'rejected';
    orderRef: string | null;
    business: string | null;
    buyer: string | null;
    requestedFor: string | null;
    agent: { name: string; ref: string | null } | null;
}

const STATE: Record<Job['status'], { label: string; className: string }> = {
    requested: { label: 'Agent being booked', className: 'bg-amber-soft text-amber-ink' },
    assigned: { label: 'Agent booked', className: 'bg-held-soft text-held-ink' },
    submitted: { label: 'Waiting for the buyer', className: 'bg-gold-soft text-gold-dark' },
    approved: { label: 'Approved', className: 'bg-green-soft text-green' },
    rejected: { label: 'Not accepted', className: 'bg-alert-soft text-alert-ink' },
};

/** Inspections & visits: agents booked to check goods at your shop, from the merchant hub board. */
export default function Inspections({ jobs }: { jobs: Job[] }) {
    return (
        <PortalShell accountName={usePage().props.auth.portal?.name ?? ''} width="page" title="Inspections & visits" subtitle="GeoVerify agents booked by your buyers to check goods before they are sent.">
            <Head title="Inspections & visits" />
            {jobs.length === 0 ? (
                <p className="max-w-none rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">No inspections booked.</p>
            ) : (
                <ul className="flex list-none flex-col gap-3 p-0">
                    {jobs.map((job) => (
                        <li key={job.id}>
                            <Link href={`/portal/sales/${String(job.orderId)}`} className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-rule bg-raised px-5 py-4 hover:border-rule-strong">
                                <span className="min-w-0">
                                    <span className="block text-label font-extrabold tracking-[0.05em] text-muted uppercase">
                                        {job.label} · <span className="numeric-mono">{job.orderRef}</span>
                                    </span>
                                    <span className="block font-bold text-ink">{job.business}</span>
                                    <span className="block text-table text-muted">
                                        For {job.buyer}
                                        {job.agent !== null && ` · agent ${job.agent.ref ?? job.agent.name}`}
                                        {job.requestedFor !== null &&
                                            ` · ${new Date(job.requestedFor).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}`}
                                    </span>
                                </span>
                                <span className={cx('rounded-full px-2.5 py-1 text-table font-bold', STATE[job.status].className)}>{STATE[job.status].label}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </PortalShell>
    );
}
