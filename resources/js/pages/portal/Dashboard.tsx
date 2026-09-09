import { Head, Link } from '@inertiajs/react';
import { motion, useReducedMotion } from 'motion/react';
import { buttonClass } from '@/lib/button';
import { OrderTracker, type TrackerStep } from '@/components/OrderTracker';
import { PortalShell } from '@/components/PortalShell';
import { StatusPill } from '@/components/StatusPill';
import { VerificationLadder } from '@/components/VerificationLadder';
import { STATUS_COLOR, type StatusTone } from '@/lib/status';
import type { Rung } from '@/lib/tiers';

interface PartySummary {
    id: number;
    code: string | null;
    displayName: string | null;
    kind: string | null;
    role: string;
    identityTier: string | null;
    listings: number;
}

interface Listing {
    enterpriseId: number;
    tradingName: string;
    ward: string | null;
    since: string;
}

interface OpenClaim {
    id: number;
    tradingName: string;
    status: string;
    statusLabel: string;
    assertedAt: string;
}

interface Focus {
    id: number;
    tradingName: string;
    ward: string | null;
    lga: string | null;
    structureType: string;
    enumeratedAt: string;
    selfRegistered: boolean;
    rungs: Rung[];
    nextRung: { tier: string; label: string; feeNaira: number; within: string } | null;
    inFlight: {
        id: number;
        reference: string;
        tier: string;
        status: string;
        statusLabel: string;
        feeNaira: number;
        dueBy: string | null;
        held: boolean;
        steps: TrackerStep[];
    } | null;
    activity: { event: string; label: string; tone: StatusTone; at: string }[];
    receipts: { token: string; granted: boolean; agreedOn: string }[];
    publication: { state: string; label: string };
}

interface DashboardProps {
    account: { name: string; phone: string };
    parties: PartySummary[];
    listings: Listing[];
    openClaims: OpenClaim[];
    focus: Focus | null;
}

function naira(amount: number): string {
    return `₦${amount.toLocaleString('en-NG')}`;
}

function on(iso: string | null): string {
    if (iso === null) {
        return 'not yet';
    }

    return new Date(iso).toLocaleDateString('en-NG', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function shortStamp(iso: string): string {
    return new Date(iso).toLocaleString('en-NG', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * What a party sees when they arrive.
 *
 * Rebuilt at M9 around one business rather than four equal boxes. The old page
 * gave the ladder, the listings, the claims and the orders a rectangle each and
 * the same weight, which answered none of the three questions somebody actually
 * opens this page with: what has my business established, is anything happening,
 * and what do I do next.
 *
 * So the ladder leads, because it is what the product is remembered by and it
 * is the argument for the next rung. What is in flight sits under it with the
 * date we promised. The margin carries what happened and what can be held.
 *
 * The empty case is still designed rather than left over: a party with nothing
 * claimed gets its code, the plain fact that nothing is attached, and the two
 * things that can be done about it.
 */
export default function Dashboard({
    account,
    parties,
    listings,
    openClaims,
    focus,
}: DashboardProps) {
    const still = useReducedMotion();
    const party = parties[0];

    const enter = (index: number) =>
        still === true
            ? {}
            : {
                  initial: { opacity: 0, y: 8 },
                  animate: { opacity: 1, y: 0 },
                  transition: { duration: 0.32, delay: 0.05 * index, ease: 'easeOut' as const },
              };

    return (
        <PortalShell accountName={account.name} width="page">
            <Head title="Your businesses" />

            {focus === null ? (
                <EmptyCase party={party} openClaims={openClaims} />
            ) : (
                <>
                    <div className="flex flex-wrap items-end justify-between gap-4 border-b border-rule pb-6">
                        <div>
                            <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                Your businesses
                            </span>
                            <h1 className="mt-2 font-display text-display-l text-ink">
                                {focus.tradingName}
                            </h1>
                            <p className="mt-2 text-body text-muted">
                                {[focus.ward, focus.lga].filter(Boolean).join(', ')}
                                {' · '}
                                {focus.structureType}
                                {' · '}
                                {focus.selfRegistered ? 'Added by you' : 'Enumerated'}{' '}
                                {on(focus.enumeratedAt)}
                            </p>
                        </div>

                        {listings.length > 1 && (
                            <Link
                                href={`/portal/businesses/${String(focus.id)}`}
                                className={buttonClass('secondary', 'console')}
                            >
                                Switch business
                            </Link>
                        )}
                    </div>

                    <div className="mt-7 flex flex-col gap-7 lg:flex-row lg:items-start">
                        <div className="flex flex-grow flex-col gap-7">
                            <motion.section
                                {...enter(0)}
                                className="rounded-sm border border-paper-edge bg-surface"
                                aria-labelledby="established"
                            >
                                <div className="flex items-baseline justify-between gap-4 border-b border-rule px-6 py-5">
                                    <h2
                                        id="established"
                                        className="font-display text-display-s text-ink"
                                    >
                                        What this business has established
                                    </h2>
                                    <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                                        {focus.rungs.filter((r) => r.state !== 'not_established')
                                            .length}{' '}
                                        of {focus.rungs.length}
                                    </span>
                                </div>

                                <div className="px-6 pt-5">
                                    <VerificationLadder rungs={focus.rungs} />
                                </div>

                                {focus.nextRung !== null && (
                                    <div className="mt-5 flex flex-wrap items-center justify-between gap-4 border-t border-rule bg-raised px-6 py-4">
                                        <div>
                                            <p className="text-ui font-medium text-ink">
                                                Establish {focus.nextRung.label.toLowerCase()}
                                            </p>
                                            <p className="mt-0.5 text-ui text-muted">
                                                An officer attends and records what they find.{' '}
                                                <span className="numeric-mono">
                                                    {naira(focus.nextRung.feeNaira)}
                                                </span>{' '}
                                                · within {focus.nextRung.within}.
                                            </p>
                                        </div>
                                        <Link
                                            href={`/portal/businesses/${String(focus.id)}/verify/${focus.nextRung.tier}`}
                                            className={buttonClass(
                                                'primary',
                                                'field-compact',
                                            )}
                                        >
                                            See what it involves
                                        </Link>
                                    </div>
                                )}
                            </motion.section>

                            {focus.inFlight !== null && (
                                <motion.section
                                    {...enter(1)}
                                    className="rounded-sm border border-paper-edge bg-surface"
                                    aria-labelledby="in-flight"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-6 py-5">
                                        <div className="flex items-center gap-3">
                                            <h2
                                                id="in-flight"
                                                className="font-display text-display-s text-ink capitalize"
                                            >
                                                {focus.inFlight.tier}
                                            </h2>
                                            <span className="numeric-mono text-mono text-faint">
                                                {focus.inFlight.reference}
                                            </span>
                                        </div>
                                        <StatusPill
                                            label={focus.inFlight.statusLabel}
                                            tone="progress"
                                        />
                                    </div>

                                    <div className="px-6 py-6">
                                        <OrderTracker steps={focus.inFlight.steps} />

                                        <div className="mt-6 flex flex-wrap items-center justify-between gap-4 border-l-2 border-held bg-sunken px-4 py-3">
                                            <p className="text-ui text-muted">
                                                <span className="font-medium text-ink">
                                                    {naira(focus.inFlight.feeNaira)}{' '}
                                                    {focus.inFlight.held ? 'held' : 'to pay'}.
                                                </span>{' '}
                                                {focus.inFlight.held
                                                    ? 'Released to us when a supervisor accepts the report.'
                                                    : 'Nothing is scheduled until the payment clears.'}
                                                {focus.inFlight.dueBy !== null &&
                                                    ` Refunded in full if we miss ${on(focus.inFlight.dueBy)}.`}
                                            </p>
                                            <Link
                                                href={`/portal/orders/${String(focus.inFlight.id)}`}
                                                className="text-ui text-muted underline underline-offset-4 hover:text-ink"
                                            >
                                                Order details
                                            </Link>
                                        </div>
                                    </div>
                                </motion.section>
                            )}
                        </div>

                        <div className="flex w-full flex-col gap-6 lg:w-[340px] lg:shrink-0">
                            {focus.activity.length > 0 && (
                                <motion.section
                                    {...enter(2)}
                                    className="rounded-sm border border-rule"
                                    aria-labelledby="activity"
                                >
                                    <h2
                                        id="activity"
                                        className="border-b border-rule px-5 py-4 text-label font-semibold tracking-[0.12em] text-muted uppercase"
                                    >
                                        Recent activity
                                    </h2>
                                    <ul className="flex list-none flex-col px-5 py-1">
                                        {focus.activity.map((entry, index) => (
                                            <li
                                                key={`${entry.event}-${entry.at}`}
                                                className="flex gap-3 border-b border-rule py-3 last:border-b-0"
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    className={`mt-1.5 size-2.5 shrink-0 ${
                                                        entry.tone === 'accepted'
                                                            ? 'rounded-full bg-green'
                                                            : entry.tone === 'review'
                                                              ? 'bg-amber'
                                                              : entry.tone === 'rejected'
                                                                ? 'rotate-45 bg-alert'
                                                                : entry.tone === 'progress'
                                                                  ? 'bg-gold'
                                                                  : `rounded-full border-[1.5px] ${STATUS_COLOR[entry.tone]} border-current`
                                                    }`}
                                                />
                                                <span className="flex flex-col gap-0.5">
                                                    <span className="text-ui text-ink">
                                                        {entry.label}
                                                    </span>
                                                    <span className="numeric-mono text-table text-faint">
                                                        {shortStamp(entry.at)}
                                                    </span>
                                                </span>
                                                {index === 0 && still !== true && (
                                                    <motion.span
                                                        aria-hidden="true"
                                                        className="ml-auto size-1.5 shrink-0 self-start rounded-full bg-gold"
                                                        initial={{ opacity: 0, scale: 0.4 }}
                                                        animate={{ opacity: 1, scale: 1 }}
                                                        transition={{ delay: 0.5, duration: 0.3 }}
                                                    />
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </motion.section>
                            )}

                            <motion.section
                                {...enter(3)}
                                className="rounded-sm border border-rule"
                                aria-labelledby="documents"
                            >
                                <h2
                                    id="documents"
                                    className="border-b border-rule px-5 py-4 text-label font-semibold tracking-[0.12em] text-muted uppercase"
                                >
                                    Documents
                                </h2>
                                <div className="flex flex-col gap-3.5 px-5 py-4">
                                    {focus.receipts.length === 0 ? (
                                        <p className="text-ui text-muted">
                                            Nothing yet. A consent receipt appears here the first
                                            time you decide about publication.
                                        </p>
                                    ) : (
                                        focus.receipts.map((receipt) => (
                                            <div key={receipt.token} className="flex gap-3">
                                                <span className="flex flex-grow flex-col gap-0.5">
                                                    <span className="text-ui text-ink">
                                                        {receipt.granted
                                                            ? 'You agreed to publication'
                                                            : 'You withdrew your agreement'}
                                                    </span>
                                                    <span className="text-table text-faint">
                                                        {on(receipt.agreedOn)}
                                                    </span>
                                                </span>
                                                <a
                                                    href={`/receipts/${receipt.token}`}
                                                    className="text-table text-muted underline underline-offset-4 hover:text-ink"
                                                >
                                                    Open
                                                </a>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </motion.section>

                            <motion.section
                                {...enter(4)}
                                className="rounded-sm border border-rule bg-raised px-5 py-4"
                                aria-labelledby="publication"
                            >
                                <h2
                                    id="publication"
                                    className="text-label font-semibold tracking-[0.12em] text-muted uppercase"
                                >
                                    Public directory
                                </h2>
                                <p className="mt-2.5 text-ui text-muted">
                                    This business is{' '}
                                    <span className="font-semibold text-ink">
                                        {focus.publication.label.toLowerCase()}
                                    </span>
                                    . Nothing appears publicly until you say so.
                                </p>
                                <Link
                                    href={`/portal/businesses/${String(focus.id)}`}
                                    className="mt-3.5 inline-flex min-h-touch items-center text-ui text-muted underline underline-offset-4 hover:text-ink"
                                >
                                    Manage this listing
                                </Link>
                            </motion.section>
                        </div>
                    </div>
                </>
            )}
        </PortalShell>
    );
}

/**
 * A party with nothing attached. Its code, the plain fact, and the two ways out.
 */
function EmptyCase({
    party,
    openClaims,
}: {
    party: PartySummary | undefined;
    openClaims: OpenClaim[];
}) {
    return (
        <>
            <span className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                {party?.code ?? 'Your account'}
            </span>
            <h1 className="mt-2 font-display text-display-l text-ink">
                {party?.displayName ?? 'Your businesses'}
            </h1>
            <p className="mt-3 max-w-[54ch] text-body text-muted">
                No business is attached to this account yet. If an officer has recorded yours, claim
                it. If not, add it and it goes on the register as Listed.
            </p>

            <div className="mt-7 flex flex-wrap gap-3">
                <Link
                    href="/portal/claim"
                    className={buttonClass('primary', 'field-compact')}
                >
                    Find your business
                </Link>
                <Link
                    href="/portal/register-business"
                    className={buttonClass('secondary', 'field-compact')}
                >
                    Add a business
                </Link>
            </div>

            {openClaims.length > 0 && (
                <section className="mt-9 rounded-sm border border-rule">
                    <h2 className="border-b border-rule px-5 py-4 text-label font-semibold tracking-[0.12em] text-muted uppercase">
                        Claims awaiting a decision
                    </h2>
                    <ul className="flex list-none flex-col px-5 py-1">
                        {openClaims.map((claim) => (
                            <li
                                key={claim.id}
                                className="flex items-center gap-3 border-b border-rule py-3 last:border-b-0"
                            >
                                <Link
                                    href={`/portal/claim/${String(claim.id)}`}
                                    className="flex-grow text-ui text-ink underline underline-offset-4"
                                >
                                    {claim.tradingName}
                                </Link>
                                <StatusPill label={claim.statusLabel} tone="review" />
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
}
