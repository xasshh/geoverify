import { Head, Link } from '@inertiajs/react';
import { EnumerateShell } from '@/components/EnumerateShell';
import type { ProjectCard } from '@/components/OrganisationParts';
import type { EnumerateFrame } from '@/lib/enumerate';

interface Progress {
    campaignCode: string;
    status: string;
    records: number;
    accepted: number;
    target: number | null;
    percent: number | null;
    qaPassRate: number | null;
    areas: { name: string; lga: string | null; cells: number }[];
    cells: number;
    officers: number;
    officerCodes: string[];
    fields: { label: string; type: string; required: boolean }[];
    timeline: { startsOn: string | null; endsOn: string | null; daysRemaining: number | null };
}

interface Props {
    frame: EnumerateFrame;
    project: ProjectCard & { area: string; notes: string | null; asked: { label: string; type: string }[]; progress: Progress | null };
}

function Figure({ label, value, of, bar }: { label: string; value: string; of?: string | undefined; bar: number | null }) {
    return (
        <div className="rounded-card border border-rule bg-raised px-5 py-4">
            <p className="text-[0.6875rem] font-extrabold tracking-[0.06em] text-muted uppercase">{label}</p>
            <p className="mt-1">
                <span className="font-display text-[1.75rem] font-extrabold text-ink">{value}</span>
                {of !== undefined && <span className="text-ui text-muted"> {of}</span>}
            </p>
            {bar !== null && (
                <span className="mt-2 block h-1.5 overflow-hidden rounded-full bg-sunken" aria-hidden="true">
                    <span className="block h-full rounded-full bg-gold" style={{ width: `${String(Math.min(100, bar))}%` }} />
                </span>
            )}
        </div>
    );
}

/**
 * A project, to board 35. Before it is live: what was asked, and that the
 * account manager is scoping it. Live: the campaign's progress as any client
 * sees it (records, ground, officers, QA, the declared fields), never the
 * commercials, never an officer's name.
 */
export default function Project({ frame, project }: Props) {
    const p = project.progress;

    return (
        <EnumerateShell
            current="projects"
            frame={frame}
            crumbs={<><Link href="/enumerate/organisation/projects" className="hover:text-ink">Projects</Link> / {project.name}</>}
            title={project.name}
            actions={
                <span className={project.status === 'live' ? 'rounded-full bg-held-soft px-3 py-1.5 text-table font-extrabold text-held-ink' : 'rounded-full bg-amber-soft px-3 py-1.5 text-table font-extrabold text-amber-ink'}>
                    {project.statusLabel}
                    {p !== null && ` · ${p.campaignCode}`}
                </span>
            }
        >
            <Head title={project.name} />

            {p === null ? (
                <section className="mb-6 rounded-card border border-dashed border-rule-strong bg-raised px-6 py-5">
                    <p className="text-body font-extrabold text-ink">{project.status === 'declined' ? 'We could not take this project on.' : 'Your account manager is scoping this project.'}</p>
                    <p className="mt-1 text-ui text-muted">
                        {project.status === 'declined'
                            ? 'Your account manager will explain why and what would work instead.'
                            : 'They agree the area, the fields, the timetable and the price with you, then deploy field teams. This page goes live as the first records come in.'}
                    </p>
                </section>
            ) : (
                <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Figure label="Records" value={p.records.toLocaleString('en-NG')} of={p.target === null ? undefined : `of ${p.target.toLocaleString('en-NG')}`} bar={p.percent} />
                    <Figure label="Area covered" value={String(p.cells)} of={p.cells === 1 ? 'cell' : 'cells'} bar={null} />
                    <Figure label="Officers in the field" value={String(p.officers)} of={p.officerCodes.length > 0 ? p.officerCodes.slice(0, 3).join(', ') : undefined} bar={null} />
                    <Figure label="QA pass rate" value={p.qaPassRate === null ? '·' : `${String(p.qaPassRate)}%`} of={`${p.accepted.toLocaleString('en-NG')} accepted`} bar={p.qaPassRate} />
                </div>
            )}

            <div className="grid gap-5 lg:grid-cols-2 lg:items-start">
                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <div className="flex items-baseline justify-between">
                        <h2 className="text-body font-extrabold text-ink">Your data fields</h2>
                        <span className="text-table text-muted">{p !== null ? `${String(p.fields.length)} declared` : `${String(project.asked.length)} asked for`}</span>
                    </div>
                    <ul className="mt-3 flex flex-col divide-y divide-rule">
                        {(p !== null && p.fields.length > 0 ? p.fields.map((f) => ({ label: f.label, type: f.type })) : project.asked).map((f) => (
                            <li key={f.label} className="flex items-center justify-between gap-3 py-2 text-ui">
                                <span className="font-bold text-ink">{f.label}</span>
                                <span className="rounded-[6px] bg-sunken px-2 py-0.5 font-mono text-[0.75rem] text-muted">{f.type}</span>
                            </li>
                        ))}
                    </ul>
                    {p !== null && p.fields.length > 0 && <p className="mt-2 text-[0.75rem] text-muted">As declared for the field teams; your account manager agreed these with you.</p>}
                </section>

                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <h2 className="text-body font-extrabold text-ink">The brief</h2>
                    <dl className="mt-3 flex flex-col gap-2 text-ui">
                        <div className="flex justify-between gap-4"><dt className="text-muted">Counting</dt><dd className="text-right font-bold text-ink">{project.subject}</dd></div>
                        <div className="flex justify-between gap-4"><dt className="text-muted">Where</dt><dd className="text-right font-bold text-ink">{project.area}</dd></div>
                        {project.target !== null && <div className="flex justify-between gap-4"><dt className="text-muted">Target</dt><dd className="font-bold text-ink">{project.target.toLocaleString('en-NG')} records</dd></div>}
                        {(p?.timeline.endsOn ?? project.wantedBy) !== null && (
                            <div className="flex justify-between gap-4"><dt className="text-muted">Ends</dt><dd className="font-bold text-ink">{new Date(p?.timeline.endsOn ?? project.wantedBy ?? '').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}</dd></div>
                        )}
                    </dl>
                    {p !== null && p.areas.length > 0 && (
                        <ul className="mt-4 flex flex-col gap-1 border-t border-rule pt-3 text-table">
                            {p.areas.map((a) => (
                                <li key={a.name} className="flex justify-between"><span className="text-ink">{a.name}{a.lga !== null && `, ${a.lga}`}</span><span className="text-muted">{a.cells} cells</span></li>
                            ))}
                        </ul>
                    )}
                    {project.notes !== null && <p className="mt-4 border-t border-rule pt-3 text-table text-muted">{project.notes}</p>}
                </section>
            </div>
        </EnumerateShell>
    );
}
