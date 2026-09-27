import { Head, Link } from '@inertiajs/react';
import { InvestorShell } from '@/components/InvestorShell';
import type { OpportunityRow } from '@/components/InvestorWidgets';

interface Report {
    orderId: number;
    reference: string;
    tier: string;
    issuedOn: string | null;
    opportunity: OpportunityRow;
}

interface Commissioned {
    id: number;
    reference: string;
    business: string | null;
    tier: string;
    status: string;
    statusLabel: string;
}

/** The visits you commissioned, and the certificates for the businesses you can see. */
export default function Reports({ reports, commissioned }: { reports: Report[]; commissioned: Commissioned[] }) {
    return (
        <InvestorShell current="reports" title="Reports" subtitle="Verification certificates, printed on demand from the register">
            <Head title="Reports" />
            <section className="mb-9">
                <div className="mb-4 flex items-baseline justify-between gap-4">
                    <h2 className="font-display text-display-s text-ink">Commissioned verifications</h2>
                    <Link href="/invest/verifications" className="text-ui font-extrabold text-gold hover:text-gold-dark">
                        Commission another
                    </Link>
                </div>
                {commissioned.length === 0 ? (
                    <p className="rounded-card border border-rule bg-raised px-6 py-8 text-center text-ui text-muted">
                        You have not commissioned a visit yet.
                    </p>
                ) : (
                    <ul className="grid list-none gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {commissioned.map((c) => (
                            <li key={c.id}>
                                <Link
                                    href={`/invest/verifications/${String(c.id)}`}
                                    className="flex h-full flex-col rounded-card border border-rule bg-raised p-5 shadow-card hover:border-gold"
                                >
                                    <span className="numeric-mono text-mono text-muted">{c.reference}</span>
                                    <span className="mt-1 text-body font-extrabold text-ink">{c.business}</span>
                                    <span className="text-ui text-muted">{c.tier}</span>
                                    <span className="mt-3 self-start rounded-full bg-gold-soft px-3 py-1 text-table font-bold text-gold-dark">
                                        {c.statusLabel}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            <h2 className="mb-4 font-display text-display-s text-ink">Certificates</h2>
            {reports.length === 0 ? (
                <p className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                    No certificates yet. A certificate appears here when an officer&apos;s report on a published business is accepted.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-card border border-rule bg-raised">
                    <table className="w-full min-w-[720px] border-collapse text-ui">
                        <thead>
                            <tr className="border-b border-rule text-left text-table text-muted">
                                <th className="px-5 py-4 font-bold">Certificate</th>
                                <th className="px-5 py-4 font-bold">Business</th>
                                <th className="px-5 py-4 font-bold">Verification</th>
                                <th className="px-5 py-4 font-bold">Issued</th>
                                <th className="px-5 py-4"><span className="sr-only">Download</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {reports.map((r) => (
                                <tr key={r.orderId} className="border-b border-rule last:border-b-0">
                                    <td className="px-5 py-4 numeric-mono text-mono text-ink">{r.reference}</td>
                                    <td className="px-5 py-4">
                                        <Link href={`/invest/opportunities/${String(r.opportunity.id)}`} className="font-extrabold text-ink hover:text-gold">
                                            {r.opportunity.name}
                                        </Link>
                                    </td>
                                    <td className="px-5 py-4 text-muted capitalize">{r.tier}</td>
                                    <td className="px-5 py-4 text-muted">{r.issuedOn}</td>
                                    <td className="px-5 py-4 text-right">
                                        <a href={`/invest/opportunities/${String(r.opportunity.id)}/certificates/${String(r.orderId)}.pdf`} className="font-extrabold text-gold hover:text-gold-dark">
                                            Download
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </InvestorShell>
    );
}
