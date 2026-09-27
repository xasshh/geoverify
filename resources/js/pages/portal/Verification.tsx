import { Head, Link, usePage } from '@inertiajs/react';
import { PortalShell } from '@/components/PortalShell';
import { VerificationLadder } from '@/components/VerificationLadder';
import { buttonClass } from '@/lib/button';
import type { Rung } from '@/lib/tiers';

interface Props {
    business: { id: number; name: string };
    canBuy: boolean;
    rungs: Rung[];
    next: { tier: string; label: string; feeNaira: number; within: string } | null;
    offers: { tier: string; name: string; feeNaira: number; within: string; live: boolean }[];
    certificates: { orderId: number; reference: string; tier: string; completedAt: string | null; ownOrder: boolean }[];
}

/** Verification: what is established, what to establish next, and the certificates. */
export default function Verification({ business, canBuy, rungs, next, offers, certificates }: Props) {
    const accountName = usePage().props.auth.portal?.name ?? business.name;
    const established = rungs.filter((r) => r.state !== 'not_established' && r.state !== 'pending').length;
    const latest = certificates[0];

    return (
        <PortalShell accountName={accountName} width="page" title="Verification" subtitle={`${business.name} · ${String(established)} of ${String(rungs.length)} established`}>
            <Head title="Verification" />

            {latest !== undefined && (
                <section className="mb-6 flex flex-wrap items-center gap-4 rounded-card border border-gold/35 bg-raised px-6 py-5">
                    <span className="flex size-12 items-center justify-center rounded-sm bg-gold-soft text-gold">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                            <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5 4.5-5" />
                        </svg>
                    </span>
                    <span className="flex-1">
                        <span className="block text-body font-extrabold text-ink">Your business is GeoVerified</span>
                        <span className="text-ui text-muted">
                            Certificate <span className="numeric-mono">{latest.reference}</span> · <span className="capitalize">{latest.tier}</span> · {latest.completedAt}
                        </span>
                    </span>
                    <a href={`/portal/orders/${String(latest.orderId)}/certificate.pdf`} className={buttonClass('secondary', 'field-compact')}>
                        View certificate
                    </a>
                </section>
            )}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px] xl:items-start">
                <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                    <h2 className="font-display text-display-s text-ink">What this business has established</h2>
                    <div className="mt-5">
                        <VerificationLadder rungs={rungs} />
                    </div>
                </section>

                <div className="flex flex-col gap-6">
                    <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <h2 className="font-display text-display-s text-ink">Get verified</h2>
                        <p className="mt-1 text-ui text-muted">An officer attends in person. The fee is held until a supervisor accepts the report, and refunded if we are late.</p>
                        <ul className="mt-4 flex list-none flex-col gap-3">
                            {offers.map((o) => (
                                <li key={o.tier} className="rounded-sm border border-rule p-4">
                                    <p className="flex items-baseline justify-between gap-3">
                                        <span className="text-ui font-extrabold text-ink">{o.name}</span>
                                        <span className="text-ui font-extrabold text-ink">₦{o.feeNaira.toLocaleString('en-NG')}</span>
                                    </p>
                                    <p className="text-table text-muted">Within {o.within}{next?.tier === o.tier && ' · recommended next'}</p>
                                    {o.live ? (
                                        <p className="mt-3 text-table font-bold text-gold-dark">Already under way</p>
                                    ) : canBuy ? (
                                        <Link href={`/portal/businesses/${String(business.id)}/verify/${o.tier}`} className={`${buttonClass(next?.tier === o.tier ? 'primary' : 'secondary', 'field-compact')} mt-3`}>
                                            See what it involves
                                        </Link>
                                    ) : (
                                        <p className="mt-3 text-table text-muted">Your role can view but not buy.</p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section className="rounded-card border border-rule bg-raised p-6 shadow-card">
                        <h2 className="font-display text-display-s text-ink">Certificates</h2>
                        {certificates.length === 0 ? (
                            <p className="mt-2 text-ui text-muted">None yet. A certificate is issued when a verification report is accepted.</p>
                        ) : (
                            <ul className="mt-3 flex list-none flex-col">
                                {certificates.map((c) => (
                                    <li key={c.orderId} className="flex items-center justify-between gap-3 border-t border-rule py-3 first:border-t-0">
                                        <span>
                                            <span className="block numeric-mono text-mono text-ink">{c.reference}</span>
                                            <span className="text-table text-muted capitalize">
                                                {c.tier} · {c.completedAt}
                                                {!c.ownOrder && ' · requested by an investor'}
                                            </span>
                                        </span>
                                        <a href={`/portal/orders/${String(c.orderId)}/certificate.pdf`} className="text-table font-extrabold text-gold hover:text-gold-dark">
                                            Download
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </PortalShell>
    );
}
