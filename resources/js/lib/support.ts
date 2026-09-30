/** Enumerate support threads, as both the requester and the desk see them. */

export interface TicketRow {
    reference: string;
    subject: string;
    status: 'open' | 'in_review' | 'resolved';
    category: string;
    requestRef: string | null;
    business: string | null;
    tier: number | null;
    openedAt: string | null;
    updatedAt: string | null;
}

export interface Thread extends TicketRow {
    messages: { id: number; mine: boolean; author: string; body: string; refundMinor: number | null; at: string }[];
}

export const TICKET_STATUS: Record<TicketRow['status'], { label: string; className: string }> = {
    open: { label: 'Open', className: 'bg-amber-soft text-amber-ink' },
    in_review: { label: 'In review', className: 'bg-held-soft text-held-ink' },
    resolved: { label: 'Resolved', className: 'bg-gold-soft text-gold-dark' },
};
