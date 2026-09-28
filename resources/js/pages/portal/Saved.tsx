import { Head, Link, router, usePage } from '@inertiajs/react';
import { DepthMark, type DirectoryEntry } from '@/components/DirectoryChrome';
import { PortalShell } from '@/components/PortalShell';

/** Saved businesses, as the directory shows them today. */
export default function Saved({ listings }: { listings: DirectoryEntry[] }) {
    return (
        <PortalShell accountName={usePage().props.auth.portal?.name ?? ''} width="page" title="Saved" subtitle="Businesses you saved from the directory.">
            <Head title="Saved" />
            {listings.length === 0 ? (
                <div className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                    <p className="mx-auto">Nothing saved yet.</p>
                    <Link href="/directory" className="mt-2 inline-block font-bold text-gold">
                        Explore the directory
                    </Link>
                </div>
            ) : (
                <ul className="flex list-none flex-col gap-3 p-0">
                    {listings.map((l) => (
                        <li key={l.id} className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-rule bg-raised px-5 py-4">
                            <Link href={`/directory/${String(l.id)}`} className="min-w-0">
                                <span className="block font-bold text-ink">{l.tradingName}</span>
                                <span className="block text-table text-muted">{[l.sector, l.ward].filter(Boolean).join(' · ')}</span>
                            </Link>
                            <span className="flex items-center gap-3">
                                <DepthMark depth={l.depth} />
                                <button
                                    type="button"
                                    onClick={() => {
                                        router.post(`/portal/saved/${String(l.id)}`, {}, { preserveScroll: true });
                                    }}
                                    className="text-table font-bold text-muted underline underline-offset-2"
                                >
                                    Remove
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </PortalShell>
    );
}
