import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { StatusPill } from '@/components/StatusPill';
import { campaignTone, on, type CampaignDossier } from '@/lib/campaign';
import { cx } from '@/lib/cx';

/**
 * The pieces a campaign is read through.
 *
 * Shared between the client's dashboard, the About dossier and the super
 * admin's view, because all three are the same facts at different depths and
 * three copies would drift the first time a number changed meaning.
 */

/**
 * A bar with its numbers beside it, never on its own.
 *
 * A percentage with no denominator is the reason people mistrust dashboards:
 * "78%" of what, out of how many, decided when. Both ends are always shown.
 */
export function Progress({
    label,
    value,
    total,
    percent,
    tone = 'gold',
    caption,
}: {
    label: string;
    value: number | string;
    total?: number | string | null;
    percent: number | null;
    tone?: 'gold' | 'green';
    caption?: string;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                    {label}
                </span>
                <span className="numeric-mono text-mono text-ink">
                    {value}
                    {total !== undefined && total !== null && (
                        <span className="text-faint"> / {total}</span>
                    )}
                </span>
            </div>

            <div
                className="h-1.5 w-full overflow-hidden rounded-sm bg-sunken"
                role="progressbar"
                aria-valuenow={percent ?? 0}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={label}
            >
                <div
                    className={cx('h-full', tone === 'green' ? 'bg-green' : 'bg-gold')}
                    style={{ width: `${String(percent ?? 0)}%` }}
                />
            </div>

            {caption !== undefined && <p className="text-label text-faint">{caption}</p>}
        </div>
    );
}

/** A block that opens, for the lists that are long more often than not. */
export function Expandable({
    summary,
    children,
    openLabel = 'Show all',
}: {
    summary: React.ReactNode;
    children: React.ReactNode;
    openLabel?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className="flex flex-col gap-2">
            {summary}
            <button
                type="button"
                onClick={() => {
                    setOpen((v) => !v);
                }}
                className="self-start text-label text-gold underline underline-offset-2"
            >
                {open ? 'Hide' : openLabel}
            </button>
            {open && children}
        </div>
    );
}

/**
 * The active campaign, as a client reads it.
 *
 * Everything on one card: what it is, how long is left, who is out, how much
 * ground, how many records and what is being recorded about them. The client
 * asks one question a fortnight and this is the answer to it, so it does not
 * live behind a tab.
 */
export function ActiveCampaignCard({ campaign }: { campaign: CampaignDossier }) {
    const { timeline, collection, coverage, deployment, schema, stakeholders } = campaign;

    return (
        <section className="rounded-card border border-rule bg-raised">
            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-rule px-5 py-4">
                <div>
                    <p className="numeric-mono text-label text-faint">{campaign.code}</p>
                    <h2 className="font-display text-display-m text-ink">{campaign.name}</h2>
                    <p className="mt-0.5 text-ui text-muted">{campaign.subjectType}</p>
                </div>
                <StatusPill
                    tone={campaignTone(campaign.status)}
                    label={campaign.statusLabel}
                    emphasis="filled"
                />
            </header>

            <div className="grid gap-6 px-5 py-5 md:grid-cols-2">
                <Progress
                    label="Timeline"
                    value={
                        timeline.daysRemaining === null
                            ? 'no end date'
                            : `${String(Math.max(0, timeline.daysRemaining))} days left`
                    }
                    percent={timeline.elapsedPercent}
                    caption={`${on(timeline.startsOn)} to ${on(timeline.endsOn)}${
                        timeline.daysElapsed === null
                            ? ''
                            : ` · ${String(timeline.daysElapsed)} days elapsed`
                    }`}
                />

                <Progress
                    label="Records gathered"
                    value={collection.gathered.toLocaleString()}
                    total={collection.target?.toLocaleString() ?? null}
                    percent={collection.percent}
                    tone="green"
                    caption={`${collection.accepted.toLocaleString()} accepted into the register so far`}
                />
            </div>

            {timeline.overrun && (
                <p className="rounded-sm bg-amber-soft mx-5 mb-5 px-3 py-2 text-ui text-muted">
                    This campaign has run past its end date and is still active.
                </p>
            )}

            <div className="grid gap-x-6 gap-y-5 border-t border-rule px-5 py-5 md:grid-cols-3">
                <div>
                    <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                        Deployment
                    </p>
                    <p className="numeric-mono text-display-s text-ink">
                        {deployment.activeCount}
                    </p>
                    <p className="text-label text-faint">
                        {deployment.activeCount === 1 ? 'agent out' : 'agents out'} on this
                        exercise
                    </p>
                    <Link
                        href={`/client/campaigns/${String(campaign.id)}#roster`}
                        className="text-label text-gold underline underline-offset-2"
                    >
                        See the roster
                    </Link>
                </div>

                <div>
                    <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                        Coverage
                    </p>
                    <p className="numeric-mono text-display-s text-ink">{coverage.areaCount}</p>
                    <p className="text-label text-faint">
                        {coverage.areaCount === 1 ? 'area' : 'areas'}
                        {coverage.states.length > 0 && ` · ${coverage.states.join(', ')}`}
                    </p>
                </div>

                <div>
                    <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                        Stakeholders
                    </p>
                    <p className="numeric-mono text-display-s text-ink">{stakeholders.total}</p>
                    <p className="text-label text-faint">
                        across {stakeholders.byCategory.length}{' '}
                        {stakeholders.byCategory.length === 1 ? 'category' : 'categories'}
                    </p>
                </div>
            </div>

            <div className="border-t border-rule px-5 py-5">
                <Expandable
                    openLabel={`Show all ${String(schema.fieldCount)} fields`}
                    summary={
                        <div>
                            <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                Data being collected
                            </p>
                            <p className="mt-1 text-ui text-ink">
                                {schema.fieldCount} fields, {schema.requiredCount} of them required.
                            </p>
                            <p className="mt-1 text-label text-faint">
                                {schema.fields
                                    .slice(0, 5)
                                    .map((field) => field.label)
                                    .join(' · ')}
                                {schema.fieldCount > 5 && ' …'}
                            </p>
                        </div>
                    }
                >
                    <ul className="flex flex-col gap-1 border-t border-rule pt-2">
                        {schema.fields.map((field) => (
                            <li key={field.id} className="flex items-baseline gap-3">
                                <span className="min-w-0 flex-1 text-ui text-ink">
                                    {field.label}
                                </span>
                                <span className="text-label text-muted">{field.typeLabel}</span>
                                <span className="numeric-mono w-16 text-right text-label text-faint">
                                    {field.isRequired ? 'required' : 'optional'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Expandable>
            </div>

            <div className="flex flex-wrap items-center gap-4 border-t border-rule px-5 py-4">
                <Link href={`/client/campaigns/${String(campaign.id)}`}>
                    <Button>Read the full brief</Button>
                </Link>
                <span className="text-label text-faint">
                    Scope, timeline, coverage, schema, stakeholders and the agent roster.
                </span>
            </div>
        </section>
    );
}

/**
 * The brief, put in front of somebody who has not read it.
 *
 * Shown once per person per version. Not a nag: it is the first thing an officer
 * or a client administrator sees about an exercise, and somebody working to
 * instructions they never read is the failure this prevents.
 */
export function CampaignIntroModal({
    campaign,
    onDismissed,
}: {
    campaign: CampaignDossier;
    onDismissed?: () => void;
}) {
    const [saving, setSaving] = useState(false);

    const acknowledge = () => {
        setSaving(true);
        router.post(
            `/client/campaigns/${String(campaign.id)}/acknowledge`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSaving(false);
                    onDismissed?.();
                },
            },
        );
    };

    /*
     * The first paragraph, unwrapped.
     *
     * Split on blank lines rather than newlines: the brief is stored hard
     * wrapped, so splitting on every newline takes two wrapped lines and cuts
     * the sentence in half. Single newlines inside a paragraph collapse to
     * spaces, which is what makes it read as prose here and stay a document on
     * the dossier page.
     */
    const shortBrief = (campaign.about ?? campaign.objective ?? '')
        .split(/\n\s*\n/)
        .map((paragraph) => paragraph.replace(/\s+/g, ' ').trim())
        .filter((paragraph) => paragraph !== '')
        .slice(0, 1)
        .join('');

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 p-4 sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-labelledby="campaign-intro-title"
        >
            <div className="max-h-[90dvh] w-full max-w-xl overflow-y-auto rounded-card border border-rule bg-raised">
                <header className="border-b border-rule px-5 py-4">
                    <p className="numeric-mono text-label text-faint">{campaign.code}</p>
                    <h2 id="campaign-intro-title" className="font-display text-display-m text-ink">
                        {campaign.name}
                    </h2>
                    <p className="mt-0.5 text-ui text-muted">{campaign.subjectType}</p>
                </header>

                <div className="flex flex-col gap-4 px-5 py-5">
                    {shortBrief !== '' && (
                        <p className="text-body text-ink">{shortBrief}</p>
                    )}

                    <div className="grid gap-3 border-t border-rule pt-4 sm:grid-cols-3">
                        {[
                            ['Runs', `${on(campaign.timeline.startsOn)} to ${on(campaign.timeline.endsOn)}`],
                            [
                                'Coverage',
                                `${String(campaign.coverage.areaCount)} ${campaign.coverage.areaCount === 1 ? 'area' : 'areas'}${
                                    campaign.coverage.states.length > 0
                                        ? `, ${campaign.coverage.states.join(', ')}`
                                        : ''
                                }`,
                            ],
                            [
                                'Target',
                                campaign.collection.target === null
                                    ? 'not set'
                                    : `${campaign.collection.target.toLocaleString()} records`,
                            ],
                        ].map(([label, value]) => (
                            <div key={label}>
                                <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                    {label}
                                </p>
                                <p className="text-ui text-ink">{value}</p>
                            </div>
                        ))}
                    </div>

                    {campaign.stakeholders.total > 0 && (
                        <div className="border-t border-rule pt-4">
                            <p className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                                Key stakeholders
                            </p>
                            <p className="mt-1 text-ui text-muted">
                                {campaign.stakeholders.byCategory
                                    .flatMap((group) => group.people)
                                    .slice(0, 4)
                                    .map((person) => person.name)
                                    .join(' · ')}
                            </p>
                        </div>
                    )}
                </div>

                <footer className="flex flex-wrap items-center gap-3 border-t border-rule px-5 py-4">
                    <Button onClick={acknowledge} busy={saving}>
                        Got it
                    </Button>
                    <Link
                        href={`/client/campaigns/${String(campaign.id)}`}
                        className="text-ui text-gold underline underline-offset-2"
                    >
                        Read the whole brief first
                    </Link>
                </footer>
            </div>
        </div>
    );
}
