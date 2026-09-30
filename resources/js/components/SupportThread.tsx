import { Link } from '@inertiajs/react';
import { cx } from '@/lib/cx';
import { kobo } from '@/lib/enumerate';
import { TICKET_STATUS, type Thread, type TicketRow } from '@/lib/support';

function when(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    const d = new Date(iso);

    return `${d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}, ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
}

/** A ticket in a list, as board 32's left column draws it. */
export function TicketItem({ ticket, active, href }: { ticket: TicketRow; active: boolean; href: string }) {
    return (
        <Link href={href} preserveScroll className={cx('block border-b border-rule px-4 py-3.5 last:border-b-0 hover:bg-sunken', active && 'border-l-4 border-l-gold bg-gold-soft/30')}>
            <span className="flex items-center justify-between gap-2">
                <span className="font-mono text-[0.75rem] text-muted">{ticket.reference}</span>
                <span className={cx('rounded-full px-2 py-0.5 text-[0.6875rem] font-extrabold', TICKET_STATUS[ticket.status].className)}>{TICKET_STATUS[ticket.status].label}</span>
            </span>
            <span className="mt-1 block text-ui font-bold text-ink">{ticket.subject}</span>
            <span className="block text-[0.75rem] text-muted">
                {[ticket.requestRef, ticket.category, when(ticket.updatedAt)].filter((p) => p !== null && p !== '').join(' · ')}
            </span>
        </Link>
    );
}

/** The conversation: the requester on the right in teal, the desk on the left. */
export function Messages({ thread }: { thread: Thread }) {
    return (
        <ol className="flex flex-col gap-4">
            {thread.messages.map((m) => (
                <li key={m.id} className={cx('flex gap-3', m.mine ? 'justify-end' : 'justify-start')}>
                    {!m.mine && (
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-sm bg-ink text-[0.75rem] font-extrabold text-inverse" aria-hidden="true">
                            GV
                        </span>
                    )}
                    <div className={cx('max-w-[80%] rounded-card px-4 py-3', m.mine ? 'bg-gold text-on-accent' : 'border border-rule bg-raised text-ink')}>
                        <p className="max-w-none text-ui whitespace-pre-line">{m.body}</p>
                        {m.refundMinor !== null && (
                            <p className="mt-2 inline-block rounded-full bg-gold-soft px-3 py-0.5 text-table font-extrabold text-gold-dark">
                                Refunded {kobo(m.refundMinor)} to the wallet
                            </p>
                        )}
                        <p className={cx('mt-1.5 text-[0.75rem]', m.mine ? 'text-on-accent/80' : 'text-muted')}>
                            {m.author} · {when(m.at)}
                        </p>
                    </div>
                </li>
            ))}
        </ol>
    );
}
