import { Head, Link } from '@inertiajs/react';
import { InvestorShell } from '@/components/InvestorShell';
import { ScoreBar } from '@/components/InvestorWidgets';
import { StatusPill } from '@/components/StatusPill';
import { orderTone, type OrderStatus } from '@/lib/status';

interface Row {
    id: number;
    reference: string;
    business: string | null;
    tier: string;
    feeNaira: number;
    status: OrderStatus;
    statusLabel: string;
    orderedAt: string | null;
    dueBy: string | null;
    certificate: boolean;
}

interface Candidate {
    id: number;
    name: string;
    sector: string | null;
    state: string | null;
    lga: string | null;
    score: number;
    lastVerified: string | null;
}

/**
 * Due diligence: the visits this organisation has commissioned, and every
 * business it can send an agent to next.
 */
export default function Verifications({ orders, opportunities }: { orders: Row[]; opportunities: Candidate[] }) {
    return (
        <InvestorShell current="reports" title="Commission due diligence" subtitle="Send field agents to verify a business before you commit">
            <Head title="Commissioned verifications" />

            <section>
                <h2 className="mb-4 font-display text-display-s text-ink">Your verifications</h2>
                {orders.length === 0 ? (
                    <p className="rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted">
                        Nothing commissioned yet. Choose a business below to send an agent.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-card border border-rule bg-raised">
                        <table className="w-full min-w-[760px] border-collapse text-ui">
                            <thead>
                                <tr className="border-b border-rule text-left text-table text-muted">
                                    <th className="px-5 py-4 font-bold">Reference</th>
                                    <th className="px-5 py-4 font-bold">Business</th>
                                    <th className="px-5 py-4 font-bold">Visit</th>
                                    <th className="px-5 py-4 font-bold">Fee</th>
                                    <th className="px-5 py-4 font-bold">Status</th>
                                    <th className="px-5 py-4 font-bold">Due</th>
                                    <th className="px-5 py-4"><span className="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.map((o) => (
                                    <tr key={o.id} className="border-b border-rule last:border-b-0">
                                        <td className="px-5 py-4 numeric-mono text-mono text-ink">{o.reference}</td>
                                        <td className="px-5 py-4 font-extrabold text-ink">{o.business}</td>
                                        <td className="px-5 py-4 text-muted">{o.tier}</td>
                                        <td className="px-5 py-4 font-extrabold text-ink">₦{o.feeNaira.toLocaleString('en-NG')}</td>
                                        <td className="px-5 py-4">
                                            <StatusPill size="sm" tone={orderTone(o.status)} label={o.statusLabel} />
                                        </td>
                                        <td className="px-5 py-4 text-muted">{o.dueBy ?? '·'}</td>
                                        <td className="px-5 py-4 text-right">
                                            <Link href={`/invest/verifications/${String(o.id)}`} className="font-extrabold text-gold hover:text-gold-dark">
                                                Open
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section className="mt-10">
                <h2 className="font-display text-display-s text-ink">Start a verification</h2>
                <p className="mt-1 mb-4 text-ui text-muted">
                    Any business that has published an opportunity. An agent attends in person and a supervisor checks the report.
                </p>
                {opportunities.length === 0 ? (
                    <p className="rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted">
                        No business has published an opportunity yet.
                    </p>
                ) : (
                    <ul className="grid list-none gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {opportunities.map((o) => (
                            <li key={o.id} className="flex flex-col rounded-card border border-rule bg-raised p-6 shadow-card">
                                <span className="font-display text-display-s text-ink">{o.name}</span>
                                <span className="mt-1 text-ui text-muted">
                                    {[o.sector, [o.lga, o.state].filter(Boolean).join(', ')].filter(Boolean).join(' · ')}
                                </span>
                                <span className="mt-4 flex items-center justify-between gap-3 text-table text-muted">
                                    <ScoreBar score={o.score} />
                                    <span>Last verified {o.lastVerified ?? 'never'}</span>
                                </span>
                                <Link
                                    href={`/invest/opportunities/${String(o.id)}/commission`}
                                    className="mt-5 inline-flex min-h-touch items-center justify-center rounded-sm bg-gold px-4 text-ui font-extrabold text-on-accent hover:bg-gold-dark"
                                >
                                    Commission a visit
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </InvestorShell>
    );
}
