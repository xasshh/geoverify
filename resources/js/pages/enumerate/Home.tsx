import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { EnumerateShell } from '@/components/EnumerateShell';
import { QuickLookup, RequestTable } from '@/components/EnumerateParts';
import { DAY_TONE, type CalendarDay, type EnumerateFrame, type Prices, type RequestRow } from '@/lib/enumerate';
import { cx } from '@/lib/cx';
import { guidePoster, guideVideo } from '@/lib/guides';

interface Props {
    frame: EnumerateFrame;
    prices: Prices;
    counts: { inProgress: number; completed: number };
    complaints: { open: number; repliedAt: string | null };
    recent: RequestRow[];
    live: { reference: string; business: string; days: number; dayToday: number | null; calendar: CalendarDay[] } | null;
    agentApplication: 'draft' | 'submitted' | 'changes_requested' | 'approved' | 'rejected' | null;
}

const AGENT_LINE: Record<NonNullable<Props['agentApplication']>, string> = {
    draft: 'Your agent application is saved but not sent yet. Pick up where you left off.',
    submitted: 'Your agent application has been sent. We will email you when we have looked at it.',
    changes_requested: 'We asked for a change to your agent application. Open it to see what.',
    approved: 'You have been approved as a GeoVerify agent. Open it for your next steps.',
    rejected: 'We could not take your agent application forward. Open it to see why.',
};

function Card({ figure, tone, title, body, href, cta }: { figure: string; tone: string; title: string; body: string; href: string; cta: string }) {
    return (
        <Link href={href} className="flex flex-col rounded-card border border-rule bg-raised px-6 py-6 hover:border-rule-strong">
            <span className={`flex size-12 items-center justify-center rounded-full font-display text-[1.375rem] font-extrabold ${tone}`}>{figure}</span>
            <span className="mt-4 text-body font-extrabold text-ink">{title}</span>
            <span className="mt-1 text-table text-muted">{body}</span>
            <span className="mt-auto pt-5 text-ui font-extrabold text-gold">
                {cta} <span aria-hidden="true">→</span>
            </span>
        </Link>
    );
}

const WELCOME_KEY = 'gv.enumerate.welcome-dismissed';

/**
 * The tour video, for somebody who has not run a check yet. Hiding it is a
 * convenience remembered in this browser only; the Help page always has it.
 */
function Welcome() {
    const [hidden, setHidden] = useState(() => {
        try {
            return window.localStorage.getItem(WELCOME_KEY) === '1';
        } catch {
            return false;
        }
    });

    if (hidden) return null;

    return (
        <section className="mb-5 grid gap-5 rounded-card border border-gold/40 bg-raised p-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] lg:items-center">
            <video
                controls
                preload="none"
                playsInline
                poster={guidePoster('dashboard-tour')}
                src={guideVideo('dashboard-tour')}
                className="aspect-video w-full rounded-sm bg-[#0F1A17]"
                aria-label="A quick tour of your dashboard"
            />
            <div>
                <p className="text-label font-extrabold tracking-[0.05em] text-gold uppercase">New here?</p>
                <h2 className="mt-1 font-display text-[1.375rem] font-extrabold text-ink">Watch a one minute tour</h2>
                <p className="mt-1 text-ui text-muted">
                    See where everything is before your first check. There are more short videos, with the steps written
                    down, on your Help page.
                </p>
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <Link href="/enumerate/help" className="rounded-full bg-gold px-5 py-2.5 text-ui font-extrabold text-on-accent hover:bg-gold-dark">
                        All help videos
                    </Link>
                    <button
                        type="button"
                        onClick={() => {
                            try {
                                window.localStorage.setItem(WELCOME_KEY, '1');
                            } catch {
                                // Storage refused (private window): hide it for this visit only.
                            }
                            setHidden(true);
                        }}
                        className="text-ui font-bold text-muted hover:text-ink"
                    >
                        Hide this
                    </button>
                </div>
            </div>
        </section>
    );
}

/**
 * Home, to board 28: a quick lookup, the new-verification card, where things
 * stand, and the latest requests. Complaints join this row when support does.
 */
export default function Home({ frame, prices, counts, recent, live, complaints, agentApplication }: Props) {
    return (
        <EnumerateShell current="home" frame={frame} title="Home">
            <Head title="Enumerate" />

            {counts.inProgress + counts.completed === 0 && <Welcome />}

            <QuickLookup tier1Minor={prices.tier1} />

            <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Link href="/enumerate/verify" className="relative flex min-h-[210px] flex-col overflow-hidden rounded-card bg-gold px-6 py-6 text-on-accent hover:bg-gold-dark">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                        <path d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4M12 15.5s-3-2.6-3-5a3 3 0 0 1 6 0c0 2.4-3 5-3 5z" />
                    </svg>
                    <span className="mt-5 font-display text-[1.375rem] font-extrabold">New verification</span>
                    <span className="mt-1.5 text-table text-on-accent/85">Registry check, site visit with photos, or up to 30 days of activity monitoring.</span>
                    <span className="mt-auto pt-5 text-ui font-extrabold">+ Verify now <span aria-hidden="true">→</span></span>
                </Link>
                <Card
                    figure={String(counts.inProgress)}
                    tone="bg-held-soft text-held-ink"
                    title="In progress"
                    body="Registers being read or agents at work"
                    href="/enumerate/verifications"
                    cta="View active"
                />
                <Card
                    figure={String(counts.completed)}
                    tone="bg-gold-soft text-gold-dark"
                    title="Completed"
                    body="Results ready to open"
                    href="/enumerate/verifications"
                    cta="View results"
                />
                <Card
                    figure={String(complaints.open)}
                    tone="bg-amber-soft text-amber-ink"
                    title={complaints.open === 1 ? 'Open complaint' : 'Open complaints'}
                    body={
                        complaints.repliedAt !== null
                            ? `Our team replied ${new Date(complaints.repliedAt).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}`
                            : complaints.open > 0
                              ? 'We reply within one working day'
                              : 'Something not right? Tell us.'
                    }
                    href="/enumerate/support"
                    cta={complaints.open > 0 ? 'View complaint' : 'Support'}
                />
            </div>

            {live !== null && (
                <Link
                    href={`/enumerate/verifications/${live.reference}`}
                    className="mt-5 flex flex-col gap-3 rounded-card border border-gold/40 bg-raised px-5 py-4 hover:border-gold lg:flex-row lg:items-center"
                >
                    <span className="w-fit rounded-[6px] bg-ink px-2.5 py-1 text-[0.75rem] font-extrabold text-inverse">TIER 3 · LIVE</span>
                    <span className="min-w-0 lg:w-[260px]">
                        <span className="block truncate text-ui font-extrabold text-ink">{live.business}</span>
                        <span className="block text-table text-muted">
                            Daily activity monitoring{live.dayToday !== null ? ` · day ${String(live.dayToday)} of ${String(live.days)}` : ' · starts tomorrow'}
                        </span>
                    </span>
                    <span className="flex flex-1 flex-wrap gap-1" aria-hidden="true">
                        {live.calendar.map((c) => (
                            <span key={c.date} className={cx('size-3.5 rounded-[3px] border', DAY_TONE[c.state], c.today && 'ring-2 ring-held')} />
                        ))}
                    </span>
                    <span className="text-ui font-extrabold whitespace-nowrap text-gold">Open log →</span>
                </Link>
            )}

            <Link
                href="/enumerate/agent"
                className="mt-5 flex flex-wrap items-center gap-3 rounded-card border border-rule bg-raised px-5 py-4 hover:border-rule-strong"
            >
                <span className="text-ui font-extrabold text-ink">{agentApplication === null ? 'Want to work with us?' : 'Your agent application'}</span>
                <span className="min-w-0 flex-1 text-table text-muted">
                    {agentApplication === null ? 'Become a GeoVerify field agent: visit businesses near you and confirm they are real.' : AGENT_LINE[agentApplication]}
                </span>
                <span className="text-ui font-extrabold whitespace-nowrap text-gold">{agentApplication === null ? 'Apply' : 'Open'} →</span>
            </Link>

            <section className="mt-5 overflow-hidden rounded-card border border-rule bg-raised">
                <div className="flex items-center justify-between gap-4 px-6 pt-5 pb-4">
                    <div>
                        <h2 className="text-body font-extrabold text-ink">Recent verifications</h2>
                        <p className="text-table text-muted">Every request has its own page</p>
                    </div>
                    <Link href="/enumerate/verifications" className="text-ui font-extrabold text-gold hover:text-gold-dark">
                        View all
                    </Link>
                </div>
                <RequestTable rows={recent} empty="Nothing checked yet. Start with a business name or a CAC number above." />
            </section>
        </EnumerateShell>
    );
}
