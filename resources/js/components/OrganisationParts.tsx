import { Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { Button } from '@/components/Button';
import { cx } from '@/lib/cx';
import { kobo, type Prices } from '@/lib/enumerate';

export interface ProjectCard {
    reference: string;
    name: string;
    subject: string;
    status: 'requested' | 'scoping' | 'live' | 'closed' | 'declined';
    statusLabel: string;
    fieldCount: number;
    target: number | null;
    wantedBy: string | null;
    requestedAt: string | null;
    records: number | null;
    percent: number | null;
    officers: number | null;
    qaPassRate: number | null;
    endsOn: string | null;
}

const PILL: Record<ProjectCard['status'], string> = {
    requested: 'bg-amber-soft text-amber-ink',
    scoping: 'bg-amber-soft text-amber-ink',
    live: 'bg-held-soft text-held-ink',
    closed: 'bg-gold-soft text-gold-dark',
    declined: 'bg-sunken text-muted',
};

function short(date: string | null): string {
    return date === null ? '·' : new Date(date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

/** A project as board 34's cards draw it: what, how far, and the three numbers under it. */
export function ProjectTile({ project }: { project: ProjectCard }) {
    return (
        <Link href={`/enumerate/organisation/projects/${project.reference}`} className="flex flex-col rounded-card border border-rule bg-raised px-5 py-5 hover:border-rule-strong">
            <span className="flex items-start justify-between gap-3">
                <span className="min-w-0">
                    <span className="block truncate text-body font-extrabold text-ink">{project.name}</span>
                    <span className="block text-table text-muted">
                        Custom enumeration · {project.subject} · {project.fieldCount} fields
                        {project.requestedAt !== null && ` · asked ${short(project.requestedAt)}`}
                    </span>
                </span>
                <span className={cx('shrink-0 rounded-full px-2.5 py-1 text-[0.75rem] font-extrabold', PILL[project.status])}>
                    {project.status === 'live' ? 'Live' : project.status === 'scoping' ? 'Scoping' : project.statusLabel}
                </span>
            </span>

            <span className="mt-4 flex justify-between text-table">
                <span className="text-muted">Records collected</span>
                <span className="font-extrabold text-ink">
                    {project.records ?? 0}
                    {project.target !== null && ` of ${project.target.toLocaleString('en-NG')}`}
                </span>
            </span>
            <span className="mt-1.5 block h-2 overflow-hidden rounded-full bg-sunken" aria-hidden="true">
                <span className="block h-full rounded-full bg-gold" style={{ width: `${String(project.percent ?? 0)}%` }} />
            </span>

            <span className="mt-4 grid grid-cols-3 gap-2">
                {(
                    [
                        [project.officers === null ? '·' : String(project.officers), 'Officers out'],
                        [project.qaPassRate === null ? '·' : `${String(project.qaPassRate)}%`, 'QA pass rate'],
                        [short(project.endsOn ?? project.wantedBy), 'Ends'],
                    ] as const
                ).map(([figure, label]) => (
                    <span key={label} className="rounded-sm bg-sunken px-3 py-2">
                        <span className="block text-body font-extrabold text-ink">{figure}</span>
                        <span className="block text-[0.75rem] text-muted">{label}</span>
                    </span>
                ))}
            </span>
        </Link>
    );
}

/**
 * Bulk verification, board 34: a CSV of businesses and one tier for all of
 * them. The whole batch is paid from the organisation wallet or none of it.
 */
export function BulkUpload({ prices, allowed, reason }: { prices: Prices; allowed: boolean; reason: string }) {
    const form = useForm<{ file: File | null; tier: 1 | 2 | 3; days: 7 | 14 | 30 }>({ file: null, tier: 2, days: 30 });
    const input = useRef<HTMLInputElement | null>(null);
    const each = form.data.tier === 1 ? prices.tier1 : form.data.tier === 2 ? prices.tier2 : (prices.tier3[String(form.data.days)] ?? 0);

    return (
        <section className="rounded-card border border-rule bg-raised px-5 py-5">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-body font-extrabold text-ink">Bulk verification</h2>
                <a href="/enumerate/organisation/bulk/template.csv" className="text-table font-extrabold text-gold hover:text-gold-dark">
                    Download CSV template
                </a>
            </div>

            {!allowed ? (
                <p className="mt-3 rounded-sm bg-sunken px-4 py-3 text-ui text-muted">{reason}</p>
            ) : (
                <form
                    className="mt-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_200px]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/enumerate/organisation/bulk', { forceFormData: true });
                    }}
                >
                    <button
                        type="button"
                        onClick={() => input.current?.click()}
                        onDragOver={(e) => { e.preventDefault(); }}
                        onDrop={(e) => {
                            e.preventDefault();
                            const file = e.dataTransfer.files[0];

                            if (file !== undefined) {
                                form.setData('file', file);
                            }
                        }}
                        className="flex min-h-[140px] flex-col items-center justify-center rounded-card border-2 border-dashed border-rule-strong px-4 text-center hover:border-gold"
                    >
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-gold" aria-hidden="true">
                            <path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 20h14" />
                        </svg>
                        <span className="mt-2 text-ui font-extrabold text-ink">{form.data.file?.name ?? 'Drop a CSV of businesses'}</span>
                        <span className="mt-1 text-table text-muted">Columns: business name, RC/BN number, TIN, address. Up to 500 lines.</span>
                        <input
                            ref={input}
                            type="file"
                            accept=".csv,text/csv"
                            className="sr-only"
                            onChange={(e) => { form.setData('file', e.target.files?.[0] ?? null); }}
                        />
                    </button>

                    <div className="flex flex-col gap-2" role="radiogroup" aria-label="Apply tier">
                        <p className="text-[0.6875rem] font-extrabold tracking-[0.06em] text-muted uppercase">Apply tier</p>
                        {(
                            [
                                [1, 'Tier 1 · Registry'],
                                [2, 'Tier 2 · Location'],
                                [3, 'Tier 3 · Activity'],
                            ] as const
                        ).map(([tier, label]) => (
                            <button
                                key={tier}
                                type="button"
                                role="radio"
                                aria-checked={form.data.tier === tier}
                                onClick={() => { form.setData('tier', tier); }}
                                className={cx('flex min-h-[40px] items-center gap-2 rounded-sm border px-3 text-table font-bold', form.data.tier === tier ? 'border-2 border-gold bg-gold-soft/40 text-ink' : 'border-rule-strong text-ink')}
                            >
                                <span className={cx('size-3.5 rounded-full border-2', form.data.tier === tier ? 'border-gold bg-gold' : 'border-rule-strong')} aria-hidden="true" />
                                {label}
                            </button>
                        ))}
                        {form.data.tier === 3 && (
                            <select
                                value={form.data.days}
                                onChange={(e) => { form.setData('days', Number(e.target.value) as 7 | 14 | 30); }}
                                aria-label="Monitoring period"
                                className="h-10 rounded-sm border border-rule-strong bg-raised px-2 text-table"
                            >
                                <option value={7}>7 days</option>
                                <option value={14}>14 days</option>
                                <option value={30}>30 days</option>
                            </select>
                        )}
                        <p className="text-[0.75rem] text-muted">{kobo(each)} a business, from the organisation wallet.</p>
                        <Button type="submit" variant="primary" busy={form.processing} disabled={form.data.file === null}>
                            Check them all
                        </Button>
                    </div>
                    {form.errors.file !== undefined && <p role="alert" className="text-ui font-semibold text-alert-ink md:col-span-2">{form.errors.file}</p>}
                </form>
            )}
        </section>
    );
}
