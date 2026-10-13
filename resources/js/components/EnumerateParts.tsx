import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { StatusPill } from '@/components/StatusPill';
import { cx } from '@/lib/cx';
import { STATUS_TONE, companyType, day, kobo, priceLabel, registration, type RegistryMatch, type RequestRow } from '@/lib/enumerate';

/** "Tier 3 · Activity" on its tier's own ground: the deeper the check, the darker the chip. */
export function TierBadge({ tier, label }: { tier: 1 | 2 | 3; label: string }) {
    return (
        <span
            className={cx(
                'inline-flex rounded-[6px] px-2 py-1 text-[0.75rem] font-extrabold whitespace-nowrap',
                tier === 3 ? 'bg-ink text-inverse' : tier === 2 ? 'bg-gold-soft text-gold-dark' : 'bg-sunken text-ink',
            )}
        >
            {label}
        </span>
    );
}

/** Every request, as Home and My verifications list them. */
export function RequestTable({ rows, empty }: { rows: RequestRow[]; empty: string }) {
    if (rows.length === 0) {
        return <p className="px-6 py-10 text-center text-ui text-muted">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-left">
                <thead>
                    <tr className="border-y border-rule bg-sunken/60 text-[0.6875rem] font-extrabold tracking-[0.06em] text-muted uppercase">
                        <th className="px-6 py-3">Reference</th>
                        <th className="px-3 py-3">Business</th>
                        <th className="px-3 py-3">Tier</th>
                        <th className="px-3 py-3">Requested</th>
                        <th className="px-3 py-3">Status</th>
                        <th className="px-3 py-3 text-right">Amount</th>
                        <th className="px-6 py-3"><span className="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.reference} className="border-b border-rule last:border-b-0">
                            <td className="px-6 py-4 font-mono text-table whitespace-nowrap text-muted">{row.reference}</td>
                            <td className="max-w-[220px] px-3 py-4 text-ui font-bold text-ink">{row.business}</td>
                            <td className="px-3 py-4"><TierBadge tier={row.tier} label={row.tierLabel} /></td>
                            <td className="px-3 py-4 text-ui whitespace-nowrap text-muted">{day(row.requestedAt)}</td>
                            <td className="px-3 py-4">
                                <StatusPill tone={STATUS_TONE[row.status]} label={row.statusLabel} size="sm" />
                                {row.statusNote !== null && <p className="mt-1 max-w-[220px] text-[0.75rem] text-muted">{row.statusNote}</p>}
                            </td>
                            <td className="px-3 py-4 text-right text-ui font-extrabold whitespace-nowrap text-ink">{kobo(row.amountMinor)}</td>
                            <td className="px-6 py-4 text-right">
                                <Link href={`/enumerate/verifications/${row.reference}`} className="text-ui font-extrabold whitespace-nowrap text-gold hover:text-gold-dark">
                                    View <span aria-hidden="true">→</span>
                                </Link>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Quick lookup: a CAC number, straight into New verification with the search
 * already run. Tier 1 by default, which is what "quick" means. By number only,
 * like the other search boxes, until the provider opens search by name.
 */
export function QuickLookup({ tier1Minor }: { tier1Minor: number }) {
    const [q, setQ] = useState('');

    return (
        <form
            className="flex flex-col gap-3 rounded-card border border-rule bg-raised px-5 py-4 lg:flex-row lg:items-center"
            onSubmit={(e) => {
                e.preventDefault();
                router.get('/enumerate/verify', { by: 'rc', q, tier: 1 });
            }}
        >
            <div className="lg:w-[170px]">
                <p className="text-ui font-extrabold text-ink">Quick lookup</p>
                <p className="text-[0.75rem] text-muted">Tier 1 · CAC + TIN</p>
            </div>
            <input
                aria-label="CAC number"
                value={q}
                onChange={(e) => { setQ(e.target.value); }}
                placeholder="CAC number, e.g. RC 1482093 or BN 3300112"
                className="h-11 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-4 text-ui text-ink placeholder:text-faint focus:border-gold focus:outline-none"
            />
            <button type="submit" disabled={q.trim().length < 3} className="h-11 rounded-sm bg-gold px-5 text-ui font-extrabold whitespace-nowrap text-on-accent hover:bg-gold-dark disabled:bg-sunken disabled:text-muted">
                Verify · {priceLabel(tier1Minor)}
            </button>
        </form>
    );
}

/**
 * What the register says about a search result, beyond its name: the kind of
 * entity, when it was registered, where, and what it does. Enough to tell the
 * right business from a namesake before paying to check it.
 */
export function RegistryFacts({ match }: { match: RegistryMatch }) {
    const facts: Array<[string, string]> = [];

    if (match.registeredOn !== null) facts.push(['Registered', day(match.registeredOn)]);
    if (match.address !== null) facts.push(['Registered address', match.address]);
    else if (match.place !== null) facts.push(['Location', match.place]);
    if (match.business !== null) facts.push(['Line of business', match.business]);

    return (
        <span className="flex min-w-0 flex-col gap-1">
            <span className="text-table text-muted">
                <span className="font-mono">{registration(match.rcNumber, match.companyType)}</span> · {match.kind ?? companyType(match.companyType)}
                {match.address !== null && match.place !== null && ` · ${match.place}`}
            </span>
            {facts.length > 0 && (
                <span className="mt-1 grid gap-x-4 gap-y-1 text-table sm:grid-cols-[max-content_minmax(0,1fr)]">
                    {facts.map(([label, value]) => (
                        <span key={label} className="contents">
                            <span className="text-muted">{label}</span>
                            <span className="font-semibold break-words text-ink">{value}</span>
                        </span>
                    ))}
                </span>
            )}
        </span>
    );
}
