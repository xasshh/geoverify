import { Head, Link } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { InvestorShell } from '@/components/InvestorShell';
import type { OpportunityRow } from '@/components/InvestorWidgets';

interface Room {
    opportunity: OpportunityRow;
    status: 'requested' | 'granted' | 'declined' | 'revoked';
    requestedAt: string | null;
    decidedAt: string | null;
    documents: number;
}

const LOOK: Record<Room['status'], { label: string; cls: string }> = {
    granted: { label: 'Open', cls: 'bg-gold-soft text-gold-dark' },
    requested: { label: 'Waiting for the business', cls: 'bg-amber-soft text-amber-ink' },
    declined: { label: 'Declined', cls: 'bg-alert-soft text-alert-ink' },
    revoked: { label: 'Closed by the business', cls: 'bg-graphite-soft text-muted' },
};

/** Every data room this organisation has asked for, and where each stands. */
export default function DataRooms({ rooms }: { rooms: Room[] }) {
    return (
        <InvestorShell current="rooms" title="Data rooms" subtitle="Documents businesses have chosen to share with you">
            <Head title="Data rooms" />
            {rooms.length === 0 ? (
                <p className="rounded-card border border-rule bg-raised px-6 py-10 text-center text-ui text-muted">
                    No data rooms yet. Open a business and choose Open data room to ask for access.
                </p>
            ) : (
                <ul className="grid list-none gap-5 md:grid-cols-2 xl:grid-cols-3">
                    {rooms.map((room) => (
                        <li key={room.opportunity.id}>
                            <Link
                                href={`/invest/opportunities/${String(room.opportunity.id)}${room.status === 'granted' ? '#documents' : ''}`}
                                className="flex h-full flex-col rounded-card border border-rule bg-raised p-6 shadow-card hover:border-gold"
                            >
                                <span className={cx('self-start rounded-full px-3 py-1 text-table font-bold', LOOK[room.status].cls)}>
                                    {LOOK[room.status].label}
                                </span>
                                <span className="mt-4 font-display text-display-s text-ink">{room.opportunity.name}</span>
                                <span className="mt-1 text-ui text-muted">
                                    {[room.opportunity.sector, room.opportunity.state].filter(Boolean).join(' · ')}
                                </span>
                                <span className="mt-auto pt-5 text-ui font-semibold text-ink">
                                    {room.status === 'granted'
                                        ? `${String(room.documents)} ${room.documents === 1 ? 'document' : 'documents'}`
                                        : `Requested ${room.requestedAt === null ? '' : new Date(room.requestedAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </InvestorShell>
    );
}
