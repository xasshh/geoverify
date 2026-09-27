import { Head, Link, router } from '@inertiajs/react';
import { motion, useReducedMotion } from 'motion/react';
import { buttonClass } from '@/lib/button';
import { OrderTracker, type TrackerStep } from '@/components/OrderTracker';
import { PortalShell } from '@/components/PortalShell';
import { StatusPill } from '@/components/StatusPill';
import { VerificationLadder } from '@/components/VerificationLadder';
import { orderTone, STATUS_COLOR, type OrderStatus, type StatusTone } from '@/lib/status';
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
    orders: OrderRow[];
}

interface OrderRow {
    id: number;
    reference: string;
    tier: string;
    status: string;
    statusLabel: string;
    feeNaira: number;
    orderedAt: string | null;
    completedAt: string | null;
    hasCertificate: boolean;
    byInvestor: boolean;
}

interface DashboardProps {
    invitations: { id: number; business: string | null; role: string }[];
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
    invitations,
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
        <PortalShell
            accountName={account.name}
            width="page"
            title={focus === null ? undefined : 'Home'}
            subtitle={
                focus === null ? undefined : (
                    <>
                        {focus.tradingName}
                        {[focus.ward, focus.lga].some(Boolean) &&
                            ` · ${[focus.ward, focus.lga].filter(Boolean).join(', ')}`}
                    </>
                )
            }
            actions={
                focus !== null && listings.length > 1 ? (
                    <Link
                        href={`/portal/businesses/${String(focus.id)}`}
                        className={buttonClass('secondary', 'field-compact')}
                    >
                        Switch business
                    </Link>
                ) : undefined
            }
        >
            <Head title="Your businesses" />

            {invitations.map((invite) => (
                <div
                    key={invite.id}
                    className="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-card border border-gold/35 bg-gold-soft px-6 py-4"
                >
                    <p className="text-ui text-gold-dark">
                        <span className="font-extrabold">{invite.business}</span> added you as{' '}
                        {invite.role.toLowerCase()}.
                    </p>
                    <button
                        type="button"
                        onClick={() => {
                            router.post(`/portal/team/${String(invite.id)}/accept`);
                        }}
                        className={buttonClass('primary', 'field-compact')}
                    >
                        Accept
                    </button>
                </div>
            ))}

            {focus === null ? (
                <EmptyCase party={party} openClaims={openClaims} />
            ) : (
                <>
                    <HomeCards focus={focus} enter={enter} />

                    <VerifiedBanner focus={focus} />

                    <div className="mt-8 flex flex-col gap-7 lg:flex-row lg:items-start">
                        <div className="flex flex-grow flex-col gap-7">
                            <motion.section
                                {...enter(0)}
                                className="rounded-card border border-rule bg-raised"
                                aria-labelledby="established"
                            >
                                <div className="flex items-baseline justify-between gap-4 border-b border-rule px-6 py-5">
                                    <h2
                                        id="established"
                                        className="font-display text-display-s text-ink"
                                    >
                                        What this business has established
                                    </h2>
                                    <span className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
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
                                    className="rounded-card border border-rule bg-raised"
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

                                        <div className="rounded-sm bg-held-soft mt-6 flex flex-wrap items-center justify-between gap-4 px-4 py-3">
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
                                    className="rounded-card border border-rule bg-raised"
                                    aria-labelledby="activity"
                                >
                                    <h2
                                        id="activity"
                                        className="border-b border-rule px-5 py-4 text-label font-semibold tracking-[0.05em] text-muted uppercase"
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
                                className="rounded-card border border-rule bg-raised"
                                aria-labelledby="documents"
                            >
                                <h2
                                    id="documents"
                                    className="border-b border-rule px-5 py-4 text-label font-semibold tracking-[0.05em] text-muted uppercase"
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
                                className="rounded-card border border-rule bg-raised px-5 py-4"
                                aria-labelledby="publication"
                            >
                                <h2
                                    id="publication"
                                    className="text-label font-semibold tracking-[0.05em] text-muted uppercase"
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

                    <OrdersTable orders={focus.orders} />
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
            <span className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
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
                <section className="mt-9 rounded-card border border-rule bg-raised">
                    <h2 className="border-b border-rule px-5 py-4 text-label font-semibold tracking-[0.05em] text-muted uppercase">
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

type Enter = (index: number) => object;

/** One of the three counts beside the action card. */
function StatCard({
    value,
    title,
    body,
    href,
    link,
    tone,
}: {
    value: string;
    title: string;
    body: string;
    href: string;
    link: string;
    tone: 'teal' | 'blue' | 'amber';
}) {
    const disc = {
        teal: 'bg-gold-soft text-gold-dark',
        blue: 'bg-held-soft text-held',
        amber: 'bg-amber-soft text-amber-ink',
    }[tone];

    return (
        <div className="grid grid-cols-[56px_1fr] gap-x-4 rounded-card border border-rule bg-raised p-5 shadow-card sm:flex sm:flex-col sm:p-6">
            <span
                className={`row-span-3 flex size-14 items-center justify-center rounded-full text-[1.75rem] font-semibold sm:size-[72px] sm:text-[2.25rem] ${disc}`}
            >
                {value}
            </span>
            <h3 className="font-display text-display-s text-ink sm:mt-5">{title}</h3>
            <p className="mt-1 text-ui text-muted sm:mt-1.5">{body}</p>
            <Link
                href={href}
                className="mt-auto inline-flex min-h-touch items-center gap-1.5 text-ui font-extrabold text-gold hover:text-gold-dark sm:min-h-0 sm:pt-5"
            >
                {link}
                <span aria-hidden="true">&rsaquo;</span>
            </Link>
        </div>
    );
}

/**
 * The guide's first rule for a dashboard: one big action card, then three
 * counts, then the working table. The action is the next thing this business
 * can establish, or, when an order is already moving, following it.
 */
function HomeCards({ focus, enter }: { focus: Focus; enter: Enter }) {
    const established = focus.rungs.filter((r) => r.state !== 'not_established').length;
    const open = focus.orders.filter(
        (o) => !['completed', 'cancelled', 'refunded'].includes(o.status),
    ).length;
    const listingHref = `/portal/businesses/${String(focus.id)}`;

    const action =
        focus.inFlight !== null
            ? {
                  title: 'Follow your verification',
                  body: `${focus.inFlight.statusLabel}. Every step is dated as it happens.`,
                  href: `/portal/orders/${String(focus.inFlight.id)}`,
                  link: 'Open the order',
              }
            : focus.nextRung !== null
              ? {
                    title: `Establish ${focus.nextRung.label.toLowerCase()}`,
                    body: `An officer attends and records what they find. ${naira(focus.nextRung.feeNaira)}, within ${focus.nextRung.within}.`,
                    href: `/portal/businesses/${String(focus.id)}/verify/${focus.nextRung.tier}`,
                    link: 'See what it involves',
                }
              : {
                    title: 'Keep your listing right',
                    body: 'Everything is established. Add photographs or correct a detail when something changes.',
                    href: listingHref,
                    link: 'Manage listing',
                };

    return (
        <motion.div
            {...enter(0)}
            className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4"
        >
            <Link
                href={action.href}
                className="group relative flex min-h-[240px] flex-col overflow-hidden rounded-card bg-gold p-7 text-on-accent shadow-card hover:bg-gold-dark"
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 120 138"
                    className="pointer-events-none absolute -top-6 -right-8 w-40 text-on-accent/15"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.5"
                >
                    <path d="M60 2 118 35v68L60 136 2 103V35z" />
                    <path d="M60 30 94 49v40L60 108 26 89V49z" />
                </svg>
                <svg
                    aria-hidden="true"
                    width="40"
                    height="40"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z" />
                    <path d="M8.5 12l2.5 2.5 4.5-5" />
                </svg>
                <span className="mt-6 font-display text-display-m">{action.title}</span>
                <span className="mt-2 text-ui text-on-accent/85">{action.body}</span>
                <span className="mt-auto inline-flex items-center gap-2 pt-6 text-ui font-extrabold">
                    {action.link}
                    <span aria-hidden="true">&rsaquo;</span>
                </span>
            </Link>

            <StatCard
                tone="teal"
                value={String(established)}
                title={`Of ${String(focus.rungs.length)} established`}
                body="What an officer or the register has confirmed about this business."
                href={listingHref}
                link="View ladder"
            />
            <StatCard
                tone="blue"
                value={String(open)}
                title="Orders open"
                body={
                    open === 0
                        ? 'No verification is under way. Nothing is held.'
                        : 'Paid or waiting for payment, and not yet accepted.'
                }
                href={focus.inFlight !== null ? `/portal/orders/${String(focus.inFlight.id)}` : '#orders'}
                link="View orders"
            />
            <StatCard
                tone="amber"
                value={String(focus.activity.length)}
                title="Recent events"
                body={`This business is ${focus.publication.label.toLowerCase()} in the public directory.`}
                href={listingHref}
                link="Manage listing"
            />
        </motion.div>
    );
}

/**
 * The strip under the cards when an officer has accepted a report: proof,
 * with the certificate one tap away. Absent otherwise, because a banner that
 * says "not verified yet" is a banner that nags.
 */
function VerifiedBanner({ focus }: { focus: Focus }) {
    const done = focus.orders.find((o) => o.hasCertificate);

    if (done === undefined) {
        return null;
    }

    return (
        <section className="mt-5 flex flex-wrap items-center gap-4 rounded-card border border-gold/35 bg-raised px-6 py-5">
            <span className="flex size-12 shrink-0 items-center justify-center rounded-sm bg-gold-soft text-gold">
                <svg
                    aria-hidden="true"
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z" />
                    <path d="M8.5 12l2.5 2.5 4.5-5" />
                </svg>
            </span>
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="text-body font-extrabold text-ink">
                    Your business is GeoVerified
                </span>
                <span className="text-ui text-muted">
                    Certificate <span className="numeric-mono">{done.reference}</span> ·{' '}
                    <span className="capitalize">{done.tier}</span> accepted {on(done.completedAt)}
                </span>
            </span>
            <a
                href={`/portal/orders/${String(done.id)}/certificate.pdf`}
                className={buttonClass('secondary', 'field-compact')}
            >
                View certificate
            </a>
        </section>
    );
}

/** Every verification order on this business, with its state in words. */
function OrdersTable({ orders }: { orders: OrderRow[] }) {
    return (
        <section id="orders" className="mt-10" aria-labelledby="orders-heading">
            <h2 id="orders-heading" className="font-display text-display-s text-ink">
                Verification orders
            </h2>

            {orders.length === 0 ? (
                <p className="mt-4 rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted">
                    No orders yet. When you buy a verification it is listed here with every
                    step dated.
                </p>
            ) : (
                <div className="mt-4 overflow-x-auto rounded-card border border-rule bg-raised">
                    <table className="w-full min-w-[640px] border-collapse text-ui">
                        <thead>
                            <tr className="border-b border-rule text-left text-table font-bold text-muted">
                                <th className="px-5 py-4 font-bold">S/N</th>
                                <th className="px-5 py-4 font-bold">Order</th>
                                <th className="px-5 py-4 font-bold">Verification</th>
                                <th className="px-5 py-4 font-bold">Fee</th>
                                <th className="px-5 py-4 font-bold">Status</th>
                                <th className="px-5 py-4 font-bold">Date</th>
                                <th className="px-5 py-4">
                                    <span className="sr-only">Open</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.map((order, index) => (
                                <tr key={order.id} className="border-b border-rule last:border-b-0">
                                    <td className="px-5 py-4 text-muted">{index + 1}</td>
                                    <td className="px-5 py-4 numeric-mono text-mono font-medium text-ink">
                                        {order.reference}
                                    </td>
                                    <td className="px-5 py-4 font-semibold text-ink">
                                        <span className="capitalize">{order.tier}</span>
                                        {order.byInvestor && (
                                            <span className="block text-table font-medium text-muted">
                                                Requested by an investor
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-5 py-4 font-extrabold text-ink">
                                        {naira(order.feeNaira)}
                                    </td>
                                    <td className="px-5 py-4">
                                        <StatusPill
                                            size="sm"
                                            tone={orderTone(order.status as OrderStatus)}
                                            label={order.statusLabel}
                                        />
                                    </td>
                                    <td className="px-5 py-4 text-muted">
                                        {order.orderedAt === null ? '' : shortStamp(order.orderedAt)}
                                    </td>
                                    <td className="px-5 py-4 text-right">
                                        {!order.byInvestor && (
                                            <Link
                                                href={`/portal/orders/${String(order.id)}`}
                                                className="font-extrabold text-gold hover:text-gold-dark"
                                            >
                                                Open
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
