import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { ConsoleShell } from '@/components/ConsoleShell';
import { cx } from '@/lib/cx';
import { ago } from '@/lib/fieldDay';

interface Job {
    id: number;
    label: string;
    status: 'requested' | 'assigned' | 'submitted';
    orderRef: string | null;
    business: string | null;
    ward: string | null;
    requestedFor: string | null;
    visitMode: 'with_me' | 'for_me' | null;
    paidAt: string | null;
    agent: { name: string; ref: string | null } | null;
    nearest: { id: number; name: string; staffRef: string | null; km: number } | null;
}

const STATE: Record<Job['status'], { label: string; className: string }> = {
    requested: { label: 'Needs an agent', className: 'bg-amber-soft text-amber-ink' },
    assigned: { label: 'With an agent', className: 'bg-held-soft text-held-ink' },
    submitted: { label: 'Waiting for the buyer', className: 'bg-green-soft text-green' },
};

function Row({ job, officers }: { job: Job; officers: { id: number; name: string; ref: string | null }[] }) {
    const [officer, setOfficer] = useState<number | ''>(job.nearest?.id ?? '');

    return (
        <li className="grid gap-3 border-b border-rule px-5 py-4 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_200px_minmax(0,320px)] lg:items-center">
            <div className="min-w-0">
                <p className="text-label font-extrabold tracking-[0.05em] text-muted uppercase">
                    {job.label} · <span className="numeric-mono">{job.orderRef}</span>
                </p>
                <p className="truncate text-body font-bold text-ink">{job.business}</p>
                <p className="text-table text-muted">
                    {job.ward ?? 'Ward not resolved'} · paid {ago(job.paidAt)}
                    {job.requestedFor !== null &&
                        ` · ${new Date(job.requestedFor).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}, ${job.visitMode === 'with_me' ? 'with the buyer' : 'for the buyer'}`}
                </p>
            </div>
            <span className={cx('w-fit rounded-full px-2.5 py-1 text-table font-bold', STATE[job.status].className)}>
                {job.agent === null ? STATE[job.status].label : `${STATE[job.status].label}: ${job.agent.name}`}
            </span>
            {job.status === 'submitted' ? (
                <span className="text-table text-muted">Report filed.</span>
            ) : (
                <div className="flex flex-col gap-1.5">
                    <div className="flex gap-2">
                        <select
                            value={officer}
                            onChange={(e) => {
                                setOfficer(e.target.value === '' ? '' : Number(e.target.value));
                            }}
                            aria-label={`Agent for ${job.orderRef ?? 'this job'}`}
                            className="h-9 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-2 text-ui text-ink"
                        >
                            <option value="">Choose an agent</option>
                            {officers.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                    {o.ref !== null ? ` · ${o.ref}` : ''}
                                </option>
                            ))}
                        </select>
                        <Button
                            variant="primary"
                            size="console"
                            disabled={officer === ''}
                            onClick={() => {
                                router.post(`/console/inspections/${String(job.id)}/assign`, { officer_id: officer }, { preserveScroll: true });
                            }}
                        >
                            {job.status === 'assigned' ? 'Reassign' : 'Send'}
                        </Button>
                    </div>
                    {job.nearest !== null && (
                        <p className="text-table text-muted">
                            Nearest today: {job.nearest.name}, {job.nearest.km} km away
                        </p>
                    )}
                </div>
            )}
        </li>
    );
}

/** Inspections and site visits buyers have paid for. */
export default function Inspections({ jobs, officers }: { jobs: Job[]; officers: { id: number; name: string; ref: string | null }[] }) {
    const page = usePage();
    const errors = page.props.errors as Record<string, string | undefined>;

    return (
        <ConsoleShell current="inspections">
            <Head title="Inspections & visits" />
            <div className="mx-auto max-w-[1200px] px-6 pb-20">
                <header className="mt-8 border-b border-rule pb-3">
                    <h1 className="font-display text-display-l text-ink">Inspections & visits</h1>
                    <p className="mt-1 text-ui text-muted">Paid for by a buyer, the money held until they approve the agent’s report.</p>
                </header>
                {page.props.flash.status !== null && <p className="mt-5 max-w-none rounded-sm bg-green-soft px-4 py-3 text-ui font-semibold text-green">{page.props.flash.status}</p>}
                {errors.officer_id !== undefined && <p className="mt-5 max-w-none rounded-sm bg-alert-soft px-4 py-3 text-ui font-semibold text-alert-ink">{errors.officer_id}</p>}
                {jobs.length === 0 ? (
                    <p className="mt-6 max-w-none rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">Nothing is waiting.</p>
                ) : (
                    <ul className="mt-6 overflow-hidden rounded-card border border-rule bg-raised">
                        {jobs.map((job) => (
                            <Row key={job.id} job={job} officers={officers} />
                        ))}
                    </ul>
                )}
            </div>
        </ConsoleShell>
    );
}
