import { Head, Link } from '@inertiajs/react';
import { EnumerateShell } from '@/components/EnumerateShell';
import { BulkUpload, ProjectTile, type ProjectCard } from '@/components/OrganisationParts';
import { kobo, type EnumerateFrame, type Prices } from '@/lib/enumerate';
import type { BatchRow, TeamMember } from '@/lib/organisation';

interface Props {
    frame: EnumerateFrame;
    prices: Prices;
    stats: { month: number; byTier: Record<string, number>; liveProjects: number; officersOut: number; needReview: number };
    projects: ProjectCard[];
    team: TeamMember[];
    batches: BatchRow[];
}

function Stat({ figure, tone, title, body, href, cta }: { figure: string; tone: string; title: string; body: string; href: string; cta: string }) {
    return (
        <Link href={href} className="flex flex-col rounded-card border border-rule bg-raised px-6 py-6 hover:border-rule-strong">
            <span className={`flex size-12 items-center justify-center rounded-full font-display text-[1.25rem] font-extrabold ${tone}`}>{figure}</span>
            <span className="mt-4 text-body font-extrabold text-ink">{title}</span>
            <span className="mt-1 text-table text-muted">{body}</span>
            <span className="mt-auto pt-5 text-ui font-extrabold text-gold">
                {cta} <span aria-hidden="true">→</span>
            </span>
        </Link>
    );
}

/**
 * The organisation's overview, to board 34: start a project, where the
 * month's checks stand, the projects running, bulk verification, and the
 * team.
 */
export default function Overview({ frame, prices, stats, projects, team, batches }: Props) {
    const org = frame.organisation;

    if (org === null) {
        return null;
    }

    return (
        <EnumerateShell current="overview" frame={frame} title="Overview" crumbs={`${org.name} · signed in as ${frame.name.split(' ')[0] ?? frame.name} (${org.roleLabel})`}>
            <Head title={`${org.name} · Enumerate`} />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Link
                    href={org.can.project ? '/enumerate/organisation/projects?new=1' : '/enumerate/organisation/projects'}
                    className="flex min-h-[210px] flex-col rounded-card bg-gold px-6 py-6 text-on-accent hover:bg-gold-dark"
                >
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                        <path d="M12 3 3 8l9 5 9-5zM3 13l9 5 9-5" />
                    </svg>
                    <span className="mt-4 font-display text-[1.25rem] leading-tight font-extrabold">New enumeration project</span>
                    <span className="mt-1.5 text-table text-on-accent/85">Define your data fields and area. We deploy field teams and you watch it live.</span>
                    <span className="mt-auto pt-4 text-ui font-extrabold">{org.can.project ? '+ Start a project →' : 'Opens once approved'}</span>
                </Link>
                <Stat
                    figure={String(stats.month)}
                    tone="bg-gold-soft text-gold-dark"
                    title="Verifications this month"
                    body={`Tier 1: ${String(stats.byTier['1'] ?? 0)} · Tier 2: ${String(stats.byTier['2'] ?? 0)} · Tier 3: ${String(stats.byTier['3'] ?? 0)}`}
                    href="/enumerate/verifications"
                    cta="View all"
                />
                <Stat
                    figure={String(stats.liveProjects)}
                    tone="bg-held-soft text-held-ink"
                    title="Live projects"
                    body={`${String(stats.officersOut)} ${stats.officersOut === 1 ? 'officer' : 'officers'} on them`}
                    href="/enumerate/organisation/projects"
                    cta="Monitor"
                />
                <Stat
                    figure={String(stats.needReview)}
                    tone="bg-amber-soft text-amber-ink"
                    title="Need your review"
                    body="Checks that failed this month"
                    href="/enumerate/verifications"
                    cta="Review now"
                />
            </div>

            <div className="mt-7 flex items-center justify-between">
                <h2 className="text-[1.1875rem] font-extrabold text-ink">Enumeration projects</h2>
                <Link href="/enumerate/organisation/projects" className="text-ui font-extrabold text-gold hover:text-gold-dark">
                    All projects
                </Link>
            </div>
            {projects.length === 0 ? (
                <p className="mt-3 max-w-none rounded-card border border-dashed border-rule-strong bg-raised px-6 py-8 text-center text-ui text-muted">
                    No projects yet. A project collects your own data fields across an area, with our field teams.
                </p>
            ) : (
                <div className="mt-3 grid gap-4 md:grid-cols-2">
                    {projects.map((p) => <ProjectTile key={p.reference} project={p} />)}
                </div>
            )}

            <div className="mt-7 grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] lg:items-start">
                <div className="flex flex-col gap-4">
                    <BulkUpload
                        prices={prices}
                        allowed={org.can.bulk}
                        reason={org.status !== 'approved' ? 'Bulk verification opens once we approve the organisation.' : 'Your seat can view checks but not buy them.'}
                    />
                    {batches.length > 0 && (
                        <ul className="overflow-hidden rounded-card border border-rule bg-raised">
                            {batches.map((b) => (
                                <li key={b.reference} className="border-b border-rule last:border-b-0">
                                    <Link href={`/enumerate/organisation/bulk/${b.reference}`} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 hover:bg-sunken">
                                        <span>
                                            <span className="font-mono text-table text-muted">{b.reference}</span>
                                            <span className="ml-2 text-ui font-bold text-ink">
                                                {b.placed} placed at Tier {b.tier}
                                                {b.refused > 0 && <span className="text-alert-ink"> · {b.refused} refused</span>}
                                            </span>
                                        </span>
                                        <span className="text-ui font-extrabold text-ink">{kobo(b.totalMinor)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <section className="rounded-card border border-rule bg-raised px-5 py-5">
                    <div className="flex items-center justify-between">
                        <h2 className="text-body font-extrabold text-ink">Team</h2>
                        <Link href="/enumerate/organisation/team" className="text-table font-extrabold text-gold hover:text-gold-dark">
                            {org.can.team ? '+ Invite' : 'View'}
                        </Link>
                    </div>
                    <ul className="mt-3 flex flex-col gap-3">
                        {team.map((m) => (
                            <li key={m.id} className="flex items-center gap-3">
                                <span aria-hidden="true" className="flex size-9 items-center justify-center rounded-full bg-gold-soft text-table font-extrabold text-gold-dark">
                                    {m.pending ? '+' : m.name.split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('')}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-ui font-bold text-ink">
                                    {m.name}
                                    {m.pending && <span className="font-normal text-muted"> · invited</span>}
                                </span>
                                <span className="rounded-full bg-sunken px-2.5 py-0.5 text-[0.75rem] font-bold text-ink">{m.roleLabel}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </EnumerateShell>
    );
}
